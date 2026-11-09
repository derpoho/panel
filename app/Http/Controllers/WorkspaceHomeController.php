<?php

namespace App\Http\Controllers;

use App\Enums\MachineEnvironment;
use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\Health\WorkspaceHealth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceHomeController extends Controller
{
    /** CPU usage at or above this percentage needs attention. */
    private const int BUSY_CPU_PERCENT = 80;

    /** Backups older than this many hours need attention. */
    private const int STALE_BACKUP_HOURS = 24;

    /**
     * The workspace home: a setup guide until the first machine is
     * connected (or the user skips it), then an overview of the fleet.
     */
    public function __invoke(Request $request, Workspace $workspace, WorkspaceHealth $health): Response|RedirectResponse
    {
        // A new account's owner goes through the welcome flow first.
        if ($workspace->onboarded_at === null && $workspace->owner_id === $request->user()->id) {
            return to_route('workspace.welcome.show', ['workspace' => $workspace->slug]);
        }

        $machines = $workspace->machines()->orderBy('name')->get();

        return Inertia::render('workspace/Home', [
            'workspace' => $workspace->toPageProps(),
            // The agent's fixes waiting for someone to approve them.
            'fixesWaiting' => AgentFix::query()->where('workspace_id', $workspace->id)->where('state', 'needs_approval')
                ->latest()->limit(20)->get(['id', 'title'])->map(fn (AgentFix $f) => ['id' => $f->id, 'title' => $f->title])->values(),
            'onboarding' => $this->onboarding($workspace, $machines),
            'onboardingDismissed' => $workspace->members()
                ->whereKey($request->user()->id)
                ->whereNotNull('user_workspace.onboarding_dismissed_at')
                ->exists(),
            'stats' => $this->stats($machines),
            'providers' => $this->providers($machines),
            'issues' => $health->issues($workspace, $machines),
            'memberCount' => $workspace->members()->count(),
            'lastBackup' => $machines->max('last_backup_at')?->diffForHumans(),
            'machines' => MachineResource::collection($machines->take(6))->resolve(),
        ]);
    }

    /**
     * Getting-started steps and whether each one is done.
     *
     * @param  Collection<int, Machine>  $machines
     * @return list<array{key: string, done: bool}>
     */
    private function onboarding(Workspace $workspace, Collection $machines): array
    {
        return [
            ['key' => 'workspace', 'done' => true],
            ['key' => 'machine', 'done' => $machines->isNotEmpty()],
            ['key' => 'service', 'done' => $machines->sum('services_count') > 0],
            ['key' => 'backups', 'done' => $machines->contains(fn (Machine $machine) => $machine->last_backup_at !== null)],
            // Choosing a plan, where a package gives somewhere to choose one,
            // as in the setup guide. No plans on a self-hosted install.
            ...(Edition::selfHosted() || app(Extensions::class)->upgradeLink() === null ? [] : [['key' => 'plan', 'done' => $workspace->plan !== null]]),
        ];
    }

    /**
     * Fleet totals for the overview cards.
     *
     * @param  Collection<int, Machine>  $machines
     * @return array{machines: int, environments: array<string, int>, services: int, averageCpu: int, busy: int, backedUp: int, cpuHistory: list<int|null>}
     */
    private function stats(Collection $machines): array
    {
        $recentBackupCutoff = now()->subHours(self::STALE_BACKUP_HOURS);

        return [
            'machines' => $machines->count(),
            'environments' => collect(MachineEnvironment::cases())
                ->mapWithKeys(fn (MachineEnvironment $environment) => [
                    $environment->value => $machines->where('environment', $environment)->count(),
                ])
                ->all(),
            'services' => (int) $machines->sum('services_count'),
            'averageCpu' => (int) round((float) ($machines->avg(fn (Machine $machine) => $machine->cpu_percent ?? 0) ?? 0)),
            'busy' => $machines->filter(fn (Machine $machine) => $machine->cpu_percent >= self::BUSY_CPU_PERCENT)->count(),
            'backedUp' => $machines->filter(fn (Machine $machine) => $machine->last_backup_at?->gte($recentBackupCutoff) ?? false)->count(),
            'cpuHistory' => $this->cpuHistory($machines),
        ];
    }

    /**
     * Average CPU across the machines for the last hour, in twelve 5-minute
     * steps, oldest first, for the CPU tile's bars. Null where nothing
     * reported in that step.
     *
     * @param  Collection<int, Machine>  $machines
     * @return list<int|null>
     */
    private function cpuHistory(Collection $machines): array
    {
        $step = 300;
        $last = intdiv(now()->getTimestamp(), $step);
        if ($machines->isEmpty()) {
            return array_fill(0, 12, null);
        }

        $buckets = MachineSample::query()
            ->whereIn('machine_id', $machines->modelKeys())
            ->where('ts', '>', ($last - 11) * $step)
            ->whereNotNull('cpu')
            ->selectRaw('(ts / '.$step.') as bucket, avg(cpu) as cpu')
            ->groupBy('bucket')
            ->pluck('cpu', 'bucket');

        return array_map(
            fn (int $bucket) => isset($buckets[$bucket]) ? (int) round((float) $buckets[$bucket]) : null,
            range($last - 11, $last),
        );
    }

    /**
     * Machine counts per hosting provider, largest first.
     *
     * @param  Collection<int, Machine>  $machines
     * @return array<int, array{provider: string, machines: int}>
     */
    private function providers(Collection $machines): array
    {
        return $machines->countBy('provider')
            ->sortDesc()
            ->map(fn (int $count, string $provider) => ['provider' => $provider, 'machines' => $count])
            ->values()
            ->all();
    }

    /**
     * Mark "Needs attention" items as read, for everyone in the workspace.
     * One comes back if it happens again.
     */
    #[Audited]
    public function dismiss(Request $request, Workspace $workspace, WorkspaceHealth $health): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'max:300'],
        ])['ids'];

        $marked = $health->dismiss($workspace, $ids);
        if ($marked > 0) {
            Activity::record($workspace, 'issues.dismiss', $marked === 1 ? '1 item' : "{$marked} items");
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }
}
