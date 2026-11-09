<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\StatusIncident;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Monitors\AlertKinds;
use App\Support\Monitors\HostResolver;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\MonitorSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Uptime monitors: URLs and ports checked from the panel, their history,
 * and where alerts go.
 */
class MonitorController extends Controller
{
    /** Points in the response time chart. */
    public const BUCKETS = 144;

    public const RANGES = ['24h' => 24, '7d' => 168];

    public function index(Workspace $workspace): Response
    {
        return Inertia::render('workspace/Monitors', [
            'workspace' => $workspace->toPageProps(),
            'monitors' => $workspace->monitors()->orderBy('name')->get()->map(fn (Monitor $m) => $m->toPageProps())->values(),
            'machines' => $this->machines($workspace),
            'limit' => $workspace->monitorLimit(),
            'minInterval' => $workspace->minMonitorInterval(),
            'alerts' => $this->alertSettings($workspace),
            // So people find the fleet status page where they think about uptime.
            // Incidents written for the status pages, open ones first.
            'incidents' => array_values($workspace->statusIncidents()->shown()->with(['updates', 'machine'])
                ->orderByRaw('resolved_at is null desc')->latest('started_at')->limit(30)->get()
                ->map(fn (StatusIncident $incident) => [...$incident->toReport(withMachine: true), 'machineId' => $incident->machine_id])
                ->all()),
            'statusPage' => [
                'available' => $workspace->hasFleetStatusPage(),
                'set' => $workspace->fleetStatusPage !== null,
                'live' => (bool) $workspace->fleetStatusPage?->live(),
                // On the main domain, not the workspace's.
                'url' => $workspace->fleetStatusPage ? rtrim((string) config('app.url'), '/').'/status/'.$workspace->fleetStatusPage->slug : null,
            ],
        ]);
    }

    public function show(Request $request, Workspace $workspace, Monitor $monitor): Response
    {
        $this->own($workspace, $monitor);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::RANGES))]])['range'] ?? '24h';
        $hours = self::RANGES[$range];

        return Inertia::render('workspace/MonitorShow', [
            'workspace' => $workspace->toPageProps(),
            'monitor' => $monitor->toPageProps(),
            'machines' => $this->machines($workspace),
            'minInterval' => $workspace->minMonitorInterval(),
            'range' => $range,
            'chart' => $this->chart($monitor, $hours),
            'uptime' => [
                'day' => $this->uptimeSince($monitor, now()->subDay()),
                'week' => $this->uptimeSince($monitor, now()->subWeek()),
                'month' => $monitor->uptime(30),
                'quarter' => $monitor->uptime(Monitor::DAYS),
            ],
            'days' => $monitor->days(Monitor::DAYS),
            'incidents' => $monitor->incidents()->latest('started_at')->limit(20)->get()
                ->map(fn (MonitorIncident $i) => $i->toPageProps())->values(),
            'checks' => $monitor->checks()->latest('checked_at')->limit(20)->get()
                ->map(fn (MonitorCheck $c) => [
                    'id' => $c->id,
                    'at' => $c->checked_at->toIso8601String(),
                    'ok' => $c->ok,
                    'ms' => $c->response_ms,
                    'code' => $c->status_code,
                    'error' => $c->error,
                ])->values(),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, MonitorRunner $runner): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        if (($full = MonitorSettings::limitReached($workspace)) !== null) {
            throw ValidationException::withMessages(['name' => $full]);
        }

        $monitor = $workspace->monitors()->create([
            ...$this->validated($request, $workspace),
            'status' => 'pending',
            'next_check_at' => now(),
        ]);

        Activity::record($workspace, 'monitor.add', $monitor->name, $monitor->machine, meta: ['target' => $monitor->target()]);

        // First result right away rather than at the next round.
        defer(fn () => $runner->run(collect([$monitor->fresh(['workspace'])])));

        return to_route('workspace.monitors.show', ['workspace' => $workspace->slug, 'monitor' => $monitor->id]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        MonitorSettings::update($monitor, $this->validated($request, $workspace));
        Activity::record($workspace, 'monitor.update', $monitor->name, $monitor->machine);

        return back();
    }

    #[Audited]
    public function pause(Request $request, Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];

        MonitorSettings::pause($monitor, (bool) $paused);
        Activity::record($workspace, $paused ? 'monitor.pause' : 'monitor.resume', $monitor->name, $monitor->machine);

        return back();
    }

    #[NotAudited('Runs the check now; what it finds is recorded as usual')]
    public function check(Workspace $workspace, Monitor $monitor, MonitorRunner $runner): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        $runner->run(collect([$monitor->load('workspace')]));

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        Activity::record($workspace, 'monitor.remove', $monitor->name);
        $monitor->delete();

        return to_route('workspace.monitors.index', ['workspace' => $workspace->slug]);
    }

    #[Audited]
    public function updateAlerts(Request $request, Workspace $workspace, HostResolver $resolver): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'email' => ['required', 'boolean'],
            'webhook' => ['nullable', 'string', 'max:2000', 'url:https'],
            'muted' => ['sometimes', 'array'],
            'muted.*' => ['string', Rule::in(AlertKinds::keys())],
        ]);
        $webhook = isset($data['webhook']) && trim($data['webhook']) !== '' ? trim($data['webhook']) : null;

        if ($webhook !== null && $resolver->resolve((string) parse_url($webhook, PHP_URL_HOST)) === []) {
            throw ValidationException::withMessages(['webhook' => 'That address can’t be reached from the panel.']);
        }

        // Left out (an older client): keep what's there.
        $muted = array_key_exists('muted', $data)
            ? array_values(array_intersect(AlertKinds::keys(), $data['muted']))
            : AlertKinds::muted($workspace);

        $workspace->forceFill(['alert_email' => $data['email'], 'alert_webhook_url' => $webhook, 'alert_muted' => $muted])->save();
        Activity::record($workspace, 'alerts.update', null, meta: ['email' => (bool) $data['email'], 'webhook' => $webhook !== null, 'muted' => $muted]);

        return back();
    }

    #[NotAudited('Sends a test alert; nothing changes')]
    public function testAlert(Request $request, Workspace $workspace, MonitorAlerts $alerts): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $url = $request->validate(['webhook' => ['required', 'string', 'max:2000', 'url:https']])['webhook'];
        $error = $alerts->test($workspace, $url);

        return response()->json(['ok' => $error === null, 'error' => $error]);
    }

    private function own(Workspace $workspace, Monitor $monitor): void
    {
        abort_unless($monitor->workspace_id === $workspace->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        return app(MonitorSettings::class)->validate($request->all(), $workspace);
    }

    /**
     * Uptime from the checks since a time, as a percentage.
     */
    private function uptimeSince(Monitor $monitor, \DateTimeInterface $since): ?float
    {
        $row = $monitor->checks()->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total, sum(case when ok then 1 else 0 end) as passed')
            ->toBase()->first();

        return $row === null || (int) $row->total === 0 ? null : round((int) $row->passed / (int) $row->total * 100, 3);
    }

    /**
     * Average response time and failures per bucket over the last hours.
     *
     * @return array{timestamps: list<int>, response: list<int|null>, failed: list<int>}
     */
    private function chart(Monitor $monitor, int $hours): array
    {
        $end = now()->getTimestamp();
        $start = $end - $hours * 3600;
        $size = intdiv($hours * 3600, self::BUCKETS);
        // Per bucket: response time total and how many, and failures.
        $totalMs = $timed = $failed = array_fill(0, self::BUCKETS, 0);

        $checks = $monitor->checks()->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at')
            ->get(['checked_at', 'ok', 'response_ms']);
        foreach ($checks as $check) {
            $i = min(self::BUCKETS - 1, max(0, intdiv($check->checked_at->getTimestamp() - $start, $size)));
            if ($check->ok && $check->response_ms !== null) {
                $totalMs[$i] += $check->response_ms;
                $timed[$i]++;
            }
            if (! $check->ok) {
                $failed[$i]++;
            }
        }

        return [
            'timestamps' => array_map(fn (int $i) => $start + $i * $size, range(0, self::BUCKETS - 1)),
            'response' => array_map(fn (int $ms, int $n) => $n > 0 ? (int) round($ms / $n) : null, $totalMs, $timed),
            'failed' => array_values($failed),
        ];
    }

    /**
     * @return list<array{id: int, name: string, ip: string}>
     */
    private function machines(Workspace $workspace): array
    {
        return array_values($workspace->machines()->orderBy('name')->get(['id', 'name', 'ip_address'])
            ->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name, 'ip' => $m->ip_address])->all());
    }

    /**
     * The webhook is a secret of sorts: only people who can change it see it.
     *
     * @return array{email: bool, webhook: string|null, webhookSet: bool}
     */
    private function alertSettings(Workspace $workspace): array
    {
        return [
            'email' => (bool) ($workspace->alert_email ?? true),
            'webhook' => Gate::allows('manageMachines', $workspace) ? $workspace->alert_webhook_url : null,
            'webhookSet' => filled($workspace->alert_webhook_url),
            'muted' => AlertKinds::muted($workspace),
        ];
    }
}
