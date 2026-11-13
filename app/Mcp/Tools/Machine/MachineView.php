<?php

namespace App\Mcp\Tools\Machine;

use App\Http\Controllers\MachineUpdatesController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MaintenanceWindow;
use App\Models\ServiceGroup;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\ServiceLabels;
use App\Support\Machines\MachinePolicies;
use App\Support\ScheduledTasks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MachineView
{
    public const UPDATE_KINDS = ['package_updates', 'package_install', 'package_install_versions', 'package_hold', 'package_add', 'reboot'];

    /** @return array<string, mixed> */
    public static function row(Machine $machine, McpContext $context, ?MachineGroup $group = null): array
    {
        return [
            ...Present::machine($machine, $context),
            'group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            'region' => $machine->region,
            'tier' => $machine->tier,
            'labels' => (object) ($machine->labels ?? []),
            'osUpdates' => self::updateSummary($machine),
        ];
    }

    /** @return array<string, mixed> */
    public static function updateSummary(Machine $machine): array
    {
        return [
            'checkedAt' => $machine->updates_checked_at?->toIso8601String(),
            'waiting' => $machine->updates_checked_at === null ? null : $machine->updates_count,
            'security' => $machine->updates_checked_at === null ? null : $machine->security_updates_count,
            'rebootRequired' => $machine->needsReboot(),
            'servicesToRestart' => array_column($machine->restarts['services'] ?? [], 'unit'),
            'held' => $machine->held_packages ?? [],
            'complete' => $machine->updates_complete,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Machine $machine, McpContext $context): array
    {
        $facts = is_array($machine->facts) ? $machine->facts : [];
        $group = $machine->machine_group_id === null ? null : $context->workspace->machineGroups()->find($machine->machine_group_id);

        return [
            ...self::row($machine, $context, $group),
            'maintenance' => $machine->inMaintenance() ? [
                'until' => $machine->maintenance_until->toIso8601String(),
                'reason' => $machine->maintenance_reason,
                'public' => $machine->maintenance_public,
            ] : null,
            'plannedMaintenance' => MaintenanceWindow::query()->where('machine_id', $machine->id)->whereIn('state', ['scheduled', 'active'])
                ->with('creator:id,name')->orderBy('starts_at')->limit(10)->get()
                ->map(fn (MaintenanceWindow $w) => $w->toCard())->all(),
            'connectedAt' => $machine->created_at?->toIso8601String(),
            'policy' => self::policy($machine),
            'facts' => self::facts($machine, $facts),
            'usage' => self::usage($machine, $facts),
            'services' => self::services($machine, $context),
            'serviceGroups' => $machine->serviceGroups()->orderBy('name')->get()->map(fn (ServiceGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'services' => count($g->services ?? []),
            ])->all(),
            'backups' => self::backups($machine),
            'statusPage' => self::statusPage($machine, $context),
            'updates' => self::lastUpdates($machine),
            'recentJobs' => self::recentJobs($machine),
            'scheduledTasks' => array_map(fn (array $task) => array_filter([
                ...$task,
                'service' => $task['service']['key'] ?? null,
            ], fn ($value) => $value !== null), ScheduledTasks::for($machine)),
            'settingsUrl' => $context->url("/machines/{$machine->id}/settings"),
        ];
    }

    /** @return array<string, mixed> */
    private static function policy(Machine $machine): array
    {
        ['policy' => $policy, 'from' => $from] = MachinePolicies::resolve($machine);

        return [
            ...MachinePolicyRefs::present($policy),
            'from' => $from,
            'waitingToReachMachine' => $machine->policy_pending,
            'daemonCanFollowAll' => DaemonJobQueue::canFollowPolicy($machine),
        ];
    }

    /** @return array<string, mixed> */
    public static function updatesJob(Machine $machine, DaemonJob $job, int $logLines = 60): array
    {
        $result = is_array($job->result) ? $job->result : [];
        $report = null;
        if (in_array($job->kind, ['package_updates', 'package_install', 'package_install_versions'], true) && $result !== []) {
            $packages = MachineUpdatesController::report($result)['packages'];
            $report = [
                'manager' => $result['manager'] ?? null,
                'total' => count($packages),
                'securityCount' => count(array_filter($packages, fn (array $p) => $p['security'])),
                'packages' => array_slice($packages, 0, 500),
                'rebootRequired' => (bool) ($result['reboot_required'] ?? false),
                'applied' => (bool) ($result['applied'] ?? false),
                'held' => array_values(array_filter(is_array($result['held'] ?? null) ? $result['held'] : [], 'is_string')),
            ];
        }
        $log = array_values($job->log ?? []);
        $state = $job->isStale() ? 'stale' : $job->state;

        return [
            'jobId' => $job->id,
            'kind' => $job->kind,
            'check' => $job->kind === 'package_updates' && ! ($job->command['job']['apply'] ?? false),
            'state' => $state,
            'finished' => in_array($job->state, McpContext::FINISHED, true),
            'phase' => $job->phase,
            'log' => $logLines > 0 ? array_slice($log, -$logLines) : [],
            'logLines' => count($log),
            'error' => $job->error,
            'canPick' => MachineUpdatesController::canPick($machine),
            'report' => $report,
            'createdAt' => $job->created_at?->toIso8601String(),
            'finishedAt' => $job->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function facts(Machine $machine, array $facts): array
    {
        $hosting = is_array($facts['hosting'] ?? null) ? $facts['hosting'] : [];
        $boot = $facts['boot_ts'] ?? null;

        return [
            'hostname' => $facts['hostname'] ?? null,
            'os' => $facts['os_name'] ?? $facts['os'] ?? null,
            'osVersion' => $facts['os_version'] ?? null,
            'kernel' => $facts['kernel'] ?? null,
            'arch' => $facts['arch'] ?? null,
            'cpuCores' => $facts['cpu_cores'] ?? null,
            'cpuModel' => $facts['cpu_model'] ?? null,
            'memoryTotalMb' => self::megabytes($facts['memory_bytes'] ?? null),
            'docker' => $machine->hasDocker(),
            'dockerVersion' => $facts['docker_version'] ?? null,
            'initSystem' => $facts['init_system'] ?? null,
            'timezone' => $facts['timezone'] ?? null,
            'bootedAt' => is_int($boot) && $boot > 0 ? Carbon::createFromTimestamp($boot)->toIso8601String() : null,
            'hosting' => [
                'provider' => $hosting['provider'] ?? $machine->provider,
                'region' => $hosting['region'] ?? null,
                'zone' => $hosting['zone'] ?? null,
                'instanceType' => $hosting['instance_type'] ?? null,
            ],
            'interfaces' => collect(is_array($facts['interfaces'] ?? null) ? $facts['interfaces'] : [])
                ->filter(fn ($i) => is_array($i) && is_string($i['name'] ?? null))
                ->take(16)
                ->map(fn (array $i) => ['name' => $i['name'], 'addresses' => array_values(array_filter((array) ($i['addresses'] ?? []), 'is_string'))])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function usage(Machine $machine, array $facts): array
    {
        $memoryTotal = self::megabytes($facts['memory_bytes'] ?? null);
        $live = collect($machine->disks ?? [])->keyBy('mount');
        $known = [];
        foreach (is_array($facts['disks'] ?? null) ? $facts['disks'] : [] as $disk) {
            if (is_array($disk) && is_string($disk['mount'] ?? null)) {
                $known[] = [
                    'mount' => $disk['mount'],
                    'device' => $disk['device'] ?? null,
                    'fsType' => $disk['fs_type'] ?? null,
                    'total' => (int) ($disk['total_bytes'] ?? $disk['total'] ?? 0),
                ];
            }
        }
        if ($known === []) {
            $known = $live->map(fn (array $d) => ['mount' => $d['mount'], 'device' => null, 'fsType' => null, 'total' => $d['total']])->values()->all();
        }

        $disks = collect($known)->take(16)->map(function (array $disk) use ($live) {
            $now = $live->get($disk['mount']);
            $total = $now['total'] ?? $disk['total'];
            $used = $now === null ? null : $now['used'];

            return [
                'mount' => $disk['mount'],
                'device' => $disk['device'],
                'fsType' => $disk['fsType'],
                'totalGb' => $total > 0 ? round($total / 1024 ** 3, 1) : null,
                'usedGb' => $used === null ? null : round($used / 1024 ** 3, 1),
                'percent' => $used !== null && $total > 0 ? min(100, (int) floor($used / $total * 100)) : null,
            ];
        })->values()->all();

        return [
            'cpuPercent' => $machine->cpu_percent,
            'memoryUsedMb' => $machine->memory_used_mb,
            'memoryTotalMb' => $memoryTotal,
            'memoryPercent' => $memoryTotal !== null && $machine->memory_used_mb !== null ? min(100, (int) floor($machine->memory_used_mb / $memoryTotal * 100)) : null,
            'diskPercent' => $machine->disk_percent,
            'disks' => $disks,
        ];
    }

    /** @return array<string, mixed> */
    private static function services(Machine $machine, McpContext $context): array
    {
        $services = $context->services($machine);
        $scanned = $machine->inventory['scanned_at'] ?? null;
        /** @var Collection<int, array<string, mixed>> $notRunning */
        $notRunning = $services->filter(fn (array $s) => ($s['status'] ?? 'unknown') !== 'running');

        return [
            'total' => $services->count(),
            'byStatus' => $services->countBy(fn (array $s) => is_string($s['status'] ?? null) ? $s['status'] : 'unknown')->sortDesc()->all(),
            'containers' => $services->filter(fn (array $s) => str_starts_with((string) $s['key'], 'docker:'))->count(),
            'managed' => $services->filter(fn (array $s) => ($s['details']['managed'] ?? null) === 'true')->count(),
            'notRunning' => $notRunning->take(15)->map(fn (array $s) => [
                'key' => $s['key'],
                'name' => ServiceLabels::display($machine, $s),
                'status' => $s['status'] ?? 'unknown',
            ])->values()->all(),
            'lastScannedAt' => is_int($scanned) ? Carbon::createFromTimestamp($scanned)->toIso8601String() : null,
            'hint' => 'list_services shows each service.',
        ];
    }

    /** @return array<string, mixed> */
    private static function backups(Machine $machine): array
    {
        $destination = $machine->backupDestination;
        $last = Backup::query()->where('machine_id', $machine->id)->latest('id')->first();

        return [
            'schedule' => $machine->backup_schedule ?? 'off',
            'destination' => $destination === null ? null : [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $destination->type,
                'where' => $destination->describe(),
                'status' => $destination->status,
            ],
            'lastBackupAt' => $machine->last_backup_at?->toIso8601String(),
            'lastRun' => $last === null ? null : [
                'service' => $last->service_name,
                'state' => $last->state,
                'trigger' => $last->trigger,
                'finishedAt' => $last->finished_at?->toIso8601String(),
                'error' => $last->error === null ? null : Str::limit($last->error, 300),
            ],
            'backupableServices' => count($machine->backupableServices()),
        ];
    }

    /** @return array<string, mixed> */
    private static function statusPage(Machine $machine, McpContext $context): array
    {
        $page = $machine->statusPage;
        $page?->setRelation('machine', $machine);
        $fleet = $context->workspace->fleetStatusPage;

        return [
            'planAllows' => $context->workspace->hasStatusPages(),
            'own' => $page === null ? null : [
                'slug' => $page->slug,
                'title' => $page->title,
                'enabled' => $page->enabled,
                'public' => $page->public,
                'live' => $page->live(),
                'url' => self::statusUrl($page->slug),
            ],
            'fleet' => $fleet === null ? null : [
                'slug' => $fleet->slug,
                'live' => $fleet->live(),
                'showsThisMachine' => $machine->uid !== null && $fleet->covers($machine),
                'url' => self::statusUrl($fleet->slug),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function lastUpdates(Machine $machine): ?array
    {
        $job = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->whereIn('kind', ['package_updates', 'package_install'])
            ->where('state', 'succeeded')
            ->latest()
            ->first();
        if ($job === null) {
            return null;
        }

        $view = self::updatesJob($machine, $job, 0);
        $report = $view['report'];

        return [
            'jobId' => $job->id,
            'checkedAt' => ($job->finished_at ?? $job->updated_at)?->toIso8601String(),
            'waiting' => $report === null || $report['applied'] ? null : $report['total'],
            'security' => $report === null || $report['applied'] ? null : $report['securityCount'],
            'applied' => $report['applied'] ?? false,
            'rebootRequired' => $report['rebootRequired'] ?? false,
            'hint' => 'get_updates_result with this jobId lists the packages.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentJobs(Machine $machine): array
    {
        return array_values(DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', '!=', 'discover')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (DaemonJob $job) => [
                'id' => $job->id,
                'kind' => $job->kind,
                'service' => $job->service,
                'state' => $job->isStale() ? 'stale' : $job->state,
                'phase' => $job->phase,
                'error' => is_array($job->error) ? Str::limit((string) ($job->error['message'] ?? ''), 300) : null,
                'createdAt' => $job->created_at?->toIso8601String(),
                'finishedAt' => $job->finished_at?->toIso8601String(),
            ])
            ->all());
    }

    private static function statusUrl(string $slug): string
    {
        return rtrim((string) config('app.url'), '/').route('status.show', $slug, false);
    }

    private static function megabytes(mixed $bytes): ?int
    {
        return is_numeric($bytes) && $bytes > 0 ? (int) round($bytes / 1048576) : null;
    }
}
