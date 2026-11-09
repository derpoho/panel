<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\ServiceSample;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\ConsoleLock;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\AppVersions;
use App\Support\Deploys\ContainerNames;
use App\Support\Deploys\ServiceLabels;
use App\Support\Files\FileJobs;
use App\Support\Machines\ServiceClones;
use App\Support\Projects\AppSettings;
use App\Support\Projects\Placement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * One discovered service on a machine: its live usage and history, its
 * logs, a console for one-off commands, and start/stop/restart/remove.
 */
class MachineServiceController extends Controller
{
    /** Chart windows, in seconds. */
    private const RANGES = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400];

    /** Most points a chart gets; readings are averaged into buckets. */
    private const MAX_POINTS = 120;

    public function show(Request $request, Workspace $workspace, Machine $machine, string $service, ConsoleLock $console): Response|RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $found = $this->find($machine, $service);

        // A container of an app ServerOS deployed: show it at the app's own
        // address, which stays the same from one version to the next.
        if (str_starts_with($service, 'docker:') && $found['app'] !== null) {
            return redirect("/machines/{$machine->id}/services/app:{$found['app']}".($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        // The container behind it, which stats, logs and jobs are kept by.
        $service = $found['key'];

        $range = array_key_exists((string) $request->query('range'), self::RANGES)
            ? (string) $request->query('range')
            : '1h';

        return Inertia::render('workspace/ServiceShow', [
            'workspace' => $workspace->toPageProps(),
            'machine' => (new MachineResource($machine))->resolve(),
            'service' => $found,
            'stats' => $machine->service_stats[$service] ?? null,
            // Containers can show their docker inspect, when the daemon is new enough.
            'removable' => self::removable($machine, $found),
            // A copy to try things on: why not, or what the dialog starts with.
            'clone' => str_starts_with($service, 'docker:') ? [
                'unavailable' => ServiceClones::unavailable($machine, $found),
                'name' => ServiceClones::suggestedName($machine, (string) ($found['app'] ?? $found['name'] ?? 'service')),
                'port' => ServiceClones::freePort($machine),
            ] : null,
            // Hidden while no release can do it yet: updating wouldn't help.
            'inspect' => str_starts_with($service, 'docker:') && (DaemonJobQueue::canInspect($machine) || DaemonJobQueue::inspectReleased())
                ? ['available' => DaemonJobQueue::canInspect($machine)]
                : null,
            'links' => collect(is_array($found['ports'] ?? null) ? $found['ports'] : [])
                ->map(fn ($port) => $machine->localAddress((int) $port))
                ->filter()
                ->values(),
            'range' => $range,
            'history' => fn () => $this->history($machine, $service, self::RANGES[$range]),
            'jobs' => fn () => DaemonJob::query()
                ->where('machine_id', $machine->id)
                ->where('service', $service)
                ->whereIn('kind', ['service_action', 'service_logs', 'service_inspect', 'service_exec', 'service_remove', 'service_repair', 'service_clone', 'adopt'])
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (DaemonJob $job) => [
                    'id' => $job->id,
                    'kind' => $job->kind,
                    'action' => $job->command['job']['action'] ?? null,
                    'command' => $job->command['job']['command'] ?? null,
                    'state' => $job->state,
                    'log' => $job->log ?? [],
                    'result' => $job->result,
                    'error' => $job->error,
                    'createdAt' => $job->created_at?->toIso8601String(),
                ]),
            // Built from code by ServerOS: what's running, and a way to ship a new version.
            'app' => ($deploy = AppVersionController::lastDeploy($machine, $found['name'] ?? null)) === null ? null : [
                'service' => $deploy->service,
                'commit' => $deploy->command['job']['commit'] ?? null,
                'fromUpload' => str_contains((string) ($deploy->command['job']['repo'] ?? ''), '/api/deploy-sources/'),
                'repo' => str_contains((string) ($deploy->command['job']['repo'] ?? ''), '/api/deploy-sources/') ? null : ($deploy->command['job']['repo'] ?? null),
                'domains' => $deploy->command['job']['domains'] ?? [],
                'mentions' => AppVersionController::mentions($deploy),
                // Git apps move by deploying their live commit again; uploads need their code on hand.
                'canMove' => filled($deploy->command['job']['repo'] ?? null) && ! str_contains((string) $deploy->command['job']['repo'], '/api/deploy-sources/')
                    || (DeploySource::fromRepoUrl($deploy->command['job']['repo'] ?? null)?->usable() ?? false),
                'deployedAt' => $deploy->created_at?->toIso8601String(),
                'snapshotBefore' => $machine->snapshotsBeforeDeploy($deploy->service),
                'database' => AppVersionController::databaseProps($machine, $deploy),
                'versions' => array_map(fn (array $v) => [
                    'commit' => $v['commit'],
                    'live' => $v['live'],
                    'deployedAt' => $v['job']->created_at?->toIso8601String(),
                    'by' => $v['job']->startedByName(),
                    'data' => $v['restore'] !== [],
                    'dataAt' => $v['dataAt'],
                ], AppVersions::for($machine, $deploy->service)),
                // Names only: values never leave the panel.
                'variables' => (function () use ($machine, $deploy) {
                    $names = array_map('strval', array_keys((array) (AppSettings::lastDeploy($machine, (string) $deploy->service)?->command['job']['env'] ?? [])));
                    sort($names);

                    return $names;
                })(),
            ],
            'commandsEnabled' => (bool) config('daemon.commands_enabled'),
            'files' => fn () => [
                'places' => FileJobs::placesFor($machine, $found),
                'maxWrite' => FileJobs::MAX_WRITE_BYTES,
                'maxRead' => FileJobs::MAX_READ_BYTES,
            ],
            'consoleLock' => fn () => [
                'twoFactorEnabled' => ! $console->requiresSetup($request->user()),
                'unlockedUntil' => $console->unlockedUntil()?->toIso8601String(),
                'minutes' => ConsoleLock::MINUTES,
            ],
        ]);
    }

    /**
     * Queue an action on the service: start, stop, restart, reload, read
     * logs, run a command, adopt it, or remove it.
     */
    #[Audited]
    public function action(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue, ConsoleLock $console): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $found = $this->find($machine, $service);
        $service = $found['key'];

        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'reload', 'logs', 'inspect', 'exec', 'adopt', 'remove', 'repair'])],
            'lines' => ['nullable', 'integer', 'between:10,5000'],
            'command' => ['required_if:action,exec', 'nullable', 'string', 'max:2000'],
            'confirm' => ['required_if:action,remove', 'nullable', 'string'],
            'delete_data' => ['nullable', 'boolean'],
            'delete_adopted_volumes' => ['nullable', 'boolean'],
        ]);
        $deleteData = (bool) ($data['delete_data'] ?? false);
        $adopted = ($found['origin'] ?? null) !== 'created';

        if ($data['action'] === 'remove' && $data['confirm'] !== $found['name']) {
            return response()->json(['errors' => ['confirm' => ['Type the service name to confirm.']]], 422);
        }
        // Looking at a container's config is a read, like discovery: no need to adopt it.
        if ($data['action'] === 'inspect') {
            if (! str_starts_with($service, 'docker:')) {
                return response()->json(['error' => 'Only containers have a config to show.'], 409);
            }
            if (! DaemonJobQueue::canInspect($machine)) {
                return response()->json(['error' => 'This machine’s daemon is too old to show container config. Update it with “sudo serverosd update” on the server.'], 409);
            }
        } elseif ($data['action'] === 'remove' && ! $found['managed']) {
            // Found, not adopted: adopt it, then remove it once that's done.
        } elseif ($data['action'] !== 'adopt' && ! $found['managed']) {
            return response()->json(['error' => 'Adopt this service first so ServerOS can control it.'], 409);
        }
        if (in_array($data['action'], ['exec', 'repair'], true) && ! $found['container']) {
            return response()->json(['error' => 'That only works for container services.'], 409);
        }
        if ($data['action'] === 'remove' && ! self::removable($machine, $found)) {
            return response()->json(['error' => str_starts_with($service, 'systemd:')
                ? 'This machine’s daemon is too old to remove a systemd service. Update it with “sudo serverosd update” on the server.'
                : 'ServerOS can only remove containers and systemd services.'], 409);
        }
        // A systemd service has no volumes: its data is never touched.
        if ($data['action'] === 'remove' && ! $found['container']) {
            $deleteData = false;
        }
        if ($data['action'] === 'remove' && $adopted && $deleteData && ! ($data['delete_adopted_volumes'] ?? false)) {
            return response()->json(['errors' => ['delete_adopted_volumes' => ['Tick the second box to delete the data of a service you adopted.']]], 422);
        }
        if ($data['action'] === 'exec' && $console->requiresSetup($request->user())) {
            return response()->json(['error' => 'Turn on two-factor authentication to use the console.', 'code' => 'two_factor_required'], 403);
        }
        if ($data['action'] === 'exec' && ! $console->isUnlocked($request->user())) {
            return response()->json(['error' => 'Unlock the console with a two-factor code first.', 'code' => 'console_locked'], 423);
        }
        if (($reason = $queue->unavailable($machine, $data['action'] === 'exec' ? 'service_exec' : null)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        [$kind, $job] = match ($data['action']) {
            'logs' => ['service_logs', ['type' => 'service_logs', 'service' => $service, 'lines' => (int) ($data['lines'] ?? 300)]],
            'inspect' => ['service_inspect', ['type' => 'service_inspect', 'service' => $service]],
            'exec' => ['service_exec', ['type' => 'service_exec', 'service' => $service, 'command' => $data['command'], 'timeout_secs' => 60]],
            'adopt' => ['adopt', ['type' => 'adopt', 'service' => $service, 'dry_run' => false]],
            'repair' => ['service_repair', ['type' => 'service_repair', 'service' => $service]],
            'remove' => ['service_remove', ['type' => 'service_remove', 'service' => $service, 'delete_data' => $deleteData, 'delete_adopted_volumes' => $adopted && $deleteData]],
            default => ['service_action', ['type' => 'service_action', 'service' => $service, 'action' => $data['action']]],
        };

        try {
            // An app's older versions, kept stopped for rollback, go first.
            if ($data['action'] === 'remove' && $found['app'] !== null) {
                foreach (Placement::containers($machine, $found['app']) as $older) {
                    if ($older['key'] !== $service && ($older['details']['managed'] ?? null) === 'true') {
                        $queue->queue($machine, $request->user(), 'service_remove', ['type' => 'service_remove', 'service' => $older['key'], 'delete_data' => false, 'delete_adopted_volumes' => false], $older['key']);
                    }
                }
            }
            $record = $data['action'] === 'remove' && ! $found['managed']
                ? $queue->queue($machine, $request->user(), 'adopt', ['type' => 'adopt', 'service' => $service, 'dry_run' => false], $service,
                    then: ['kind' => $kind, 'job' => $job, 'service' => $service])
                : $queue->queue($machine, $request->user(), $kind, $job, $service);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }

    /**
     * Give a service a name of your own in the panel, or clear it to go back
     * to the one ServerOS found. Nothing on the machine changes.
     */
    #[Audited]
    public function rename(Request $request, Workspace $workspace, Machine $machine, string $service): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $found = $this->find($machine, $service);

        $label = $request->validate(['label' => ['nullable', 'string', 'max:60']])['label'] ?? null;
        $before = ServiceLabels::display($machine, $found);
        ServiceLabels::set($machine, $found, $label);
        $after = ServiceLabels::display($machine->fresh(), $found);

        if ($before !== $after) {
            Activity::record($workspace, 'service.rename', $after, $machine, meta: ['from' => $before]);
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * Hide services from the panel's lists, or show them again: one, or
     * several picked in the list. Nothing changes on the machine.
     */
    #[Audited]
    public function hide(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'services' => ['required', 'array', 'min:1', 'max:500'],
            'services.*' => ['required', 'string', 'max:300'],
            'hidden' => ['required', 'boolean'],
        ]);

        $found = array_map(fn (string $key) => $this->find($machine, $key), array_values(array_unique($data['services'])));
        $changed = array_values(array_filter($found, fn (array $service) => ServiceLabels::isHidden($machine, $service) !== (bool) $data['hidden']));
        if ($changed === []) {
            AuditContext::nothingChanged();

            return back();
        }

        ServiceLabels::setHidden($machine, $changed, (bool) $data['hidden']);
        $subject = count($changed) === 1 ? ServiceLabels::display($machine, $changed[0]) : count($changed).' services';
        Activity::record($workspace, $data['hidden'] ? 'service.hide' : 'service.unhide', $subject, $machine);

        return back();
    }

    /**
     * Stop keeping a service's log lines, or start again: for a chatty
     * service whose logs nobody reads, so they don't use up the workspace's
     * daily allowance.
     */
    #[Audited]
    public function muteLogs(Request $request, Workspace $workspace, Machine $machine, string $service): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['muted' => ['required', 'boolean']]);
        $found = $this->find($machine, $service);

        if (ServiceLabels::logsMuted($machine, $found) === (bool) $data['muted']) {
            AuditContext::nothingChanged();

            return back();
        }

        ServiceLabels::setLogsMuted($machine, $found, (bool) $data['muted']);
        Activity::record($workspace, $data['muted'] ? 'service.logs_mute' : 'service.logs_unmute', ServiceLabels::display($machine, $found), $machine);

        return back();
    }

    /**
     * Start a copy of a container to try things on: the same image, command
     * and environment under a new name, on its own port or domain, with its
     * data copied or empty. The original is only read.
     */
    #[Audited]
    public function clone(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $found = $this->find($machine, $service);

        if (($reason = ServiceClones::unavailable($machine, $found)) !== null) {
            return response()->json(['error' => $reason], 409);
        }
        $data = Validator::make(ServiceClones::normalise($request->all()), ServiceClones::rules($machine))->validate();

        try {
            $record = $queue->queue($machine, $request->user(), 'service_clone', ServiceClones::job($machine, $service, $data), $service, 3600);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }

    /** Upload an icon for a service, replacing any it had. Nothing changes on the machine. */
    #[NotAudited('Cosmetic')]
    public function updateIcon(Request $request, Workspace $workspace, Machine $machine, string $service): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $found = $this->find($machine, $service);
        $request->validate(['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);

        ServiceLabels::setIcon($machine, $found, $request->file('icon'));

        return back();
    }

    #[NotAudited('Cosmetic')]
    public function destroyIcon(Workspace $workspace, Machine $machine, string $service): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        ServiceLabels::setIcon($machine, $this->find($machine, $service), null);

        return back();
    }

    /**
     * The service from the machine's latest inventory, or a 404.
     *
     * @return array<string, mixed>
     */
    private function find(Machine $machine, string $key): array
    {
        $service = null;
        if (str_starts_with($key, 'app:')) {
            $service = AppReleases::find($machine, substr($key, 4));
        } else {
            foreach ($machine->inventory['services'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['key'] ?? null) === $key) {
                    $service = $candidate;

                    break;
                }
            }
        }
        abort_if($service === null, 404);
        $release = AppReleases::parse($service['name'] ?? null);

        return [
            ...$service,
            // Apps deployed from code: their stable address and plain name.
            'app' => $release !== null && isset(AppReleases::live($machine)[$release['app']]) ? $release['app'] : null,
            'label' => ServiceLabels::for($machine, $service),
            'found' => ContainerNames::found($service),
            'icon' => ServiceLabels::iconUrl($machine, $service),
            'managed' => ($service['details']['managed'] ?? null) === 'true',
            'hidden' => ServiceLabels::isHidden($machine, $service),
            'logsMuted' => ServiceLabels::logsMuted($machine, $service),
            'container' => str_starts_with((string) $service['key'], 'docker:'),
        ];
    }

    /**
     * Readings averaged into at most MAX_POINTS buckets over the window.
     * Network counters are cumulative, so they become rates per second.
     *
     * @return list<array{ts: int, cpu: float|null, mem: int|null, rx: float|null, tx: float|null}>
     */
    private function history(Machine $machine, string $service, int $window): array
    {
        $since = now()->getTimestamp() - $window;
        $bucket = max(15, (int) ceil($window / self::MAX_POINTS));

        $rows = ServiceSample::query()
            ->where('machine_id', $machine->id)
            ->where('service', $service)
            ->where('ts', '>=', $since)
            ->orderBy('ts')
            ->get(['ts', 'cpu', 'mem_used', 'net_rx', 'net_tx']);

        $points = [];
        $previous = null;
        foreach ($rows->groupBy(fn (ServiceSample $s) => intdiv($s->ts, $bucket)) as $group) {
            $last = $group->last();
            $rx = $tx = null;
            if ($previous !== null && $last->ts > $previous->ts && $last->net_rx !== null && $previous->net_rx !== null) {
                $seconds = $last->ts - $previous->ts;
                $rx = max(0, $last->net_rx - $previous->net_rx) / $seconds;
                $tx = max(0, ($last->net_tx ?? 0) - ($previous->net_tx ?? 0)) / $seconds;
            }
            $previous = $last;

            $points[] = [
                'ts' => (int) $last->ts,
                'cpu' => $group->avg('cpu') === null ? null : round((float) $group->avg('cpu'), 2),
                'mem' => $group->avg('mem_used') === null ? null : (int) $group->avg('mem_used'),
                'rx' => $rx === null ? null : round($rx, 1),
                'tx' => $tx === null ? null : round($tx, 1),
            ];
        }

        return $points;
    }

    /**
     * Whether ServerOS can remove it: any container, and a systemd service
     * once the machine's daemon knows how.
     *
     * @param  array<string, mixed>  $found
     */
    private static function removable(Machine $machine, array $found): bool
    {
        $key = (string) ($found['key'] ?? '');

        return ($found['container'] ?? false) === true
            || (str_starts_with($key, 'systemd:') && DaemonJobQueue::canRemoveSystemd($machine));
    }
}
