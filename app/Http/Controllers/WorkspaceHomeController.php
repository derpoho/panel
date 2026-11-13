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
use App\Support\Updates\PanelUpdates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceHomeController extends Controller
{
    private const int BUSY_CPU_PERCENT = 80;

    private const int STALE_BACKUP_HOURS = 24;

    /** @var array<string, array{0: int, 1: int}> */
    private const array RANGES = [
        '1h' => [60, 60],
        '24h' => [900, 96],
        '7d' => [7200, 84],
        '30d' => [28800, 90],
    ];

    public function __invoke(Request $request, Workspace $workspace, WorkspaceHealth $health): Response|RedirectResponse
    {
        if ($workspace->onboarded_at === null && $workspace->owner_id === $request->user()->id) {
            return to_route('workspace.welcome.show', ['workspace' => $workspace->slug]);
        }

        $machines = $workspace->machines()->orderBy('name')->get();

        return Inertia::render('workspace/Home', [
            'workspace' => $workspace->toPageProps(),
            'fixesWaiting' => AgentFix::query()->where('workspace_id', $workspace->id)->where('state', 'needs_approval')
                ->latest()->limit(20)->get(['id', 'title'])->map(fn (AgentFix $f) => ['id' => $f->id, 'title' => $f->title])->values(),
            'onboarding' => $this->onboarding($workspace, $machines),
            'onboardingDismissed' => $workspace->members()
                ->whereKey($request->user()->id)
                ->whereNotNull('user_workspace.onboarding_dismissed_at')
                ->exists(),
            'stats' => $this->stats($machines, $this->range($request)),
            'providers' => $this->providers($machines),
            'issues' => $health->issues($workspace, $machines),
            'memberCount' => $workspace->members()->count(),
            'lastBackup' => $machines->max('last_backup_at')?->diffForHumans(),
            'machines' => MachineResource::collection($machines->take(6))->resolve(),
            'panelUpdate' => Edition::installAdmin($request->user(), $workspace) ? PanelUpdates::notice() : null,
            ...app(Extensions::class)->pagePropsFor('workspace/Home', $request, $workspace),
        ]);
    }

    /**
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
            ...(Edition::selfHosted() || app(Extensions::class)->upgradeLink() === null ? [] : [['key' => 'plan', 'done' => $workspace->plan !== null]]),
        ];
    }

    private function range(Request $request): string
    {
        $range = $request->query('range');

        return is_string($range) && isset(self::RANGES[$range]) ? $range : '24h';
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return array{machines: int, environments: array<string, int>, services: int, averageCpu: int, busy: int, backedUp: int, cpuHistory: list<int|null>, cpuTimeline: list<int|null>, cpuRange: string}
     */
    private function stats(Collection $machines, string $range): array
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
            'cpuTimeline' => $this->cpuHistory($machines, ...self::RANGES[$range]),
            'cpuRange' => $range,
        ];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<int|null>
     */
    private function cpuHistory(Collection $machines, int $step = 300, int $count = 12): array
    {
        $last = intdiv(now()->getTimestamp(), $step);
        if ($machines->isEmpty()) {
            return array_fill(0, $count, null);
        }

        $buckets = MachineSample::query()
            ->whereIn('machine_id', $machines->modelKeys())
            ->where('ts', '>', ($last - $count + 1) * $step)
            ->whereNotNull('cpu')
            ->selectRaw(match ($step) {
                60 => '(ts / 60) as bucket, avg(cpu) as cpu',
                900 => '(ts / 900) as bucket, avg(cpu) as cpu',
                7200 => '(ts / 7200) as bucket, avg(cpu) as cpu',
                28800 => '(ts / 28800) as bucket, avg(cpu) as cpu',
                default => '(ts / 300) as bucket, avg(cpu) as cpu',
            })
            ->groupBy('bucket')
            ->pluck('cpu', 'bucket');

        return array_map(
            fn (int $bucket) => isset($buckets[$bucket]) ? (int) round((float) $buckets[$bucket]) : null,
            range($last - $count + 1, $last),
        );
    }

    /**
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
