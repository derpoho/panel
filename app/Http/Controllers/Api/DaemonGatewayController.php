<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyMachinePolicy;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\ServiceSample;
use App\Models\StatusPage;
use App\Support\Backups\BackupRunner;
use App\Support\DaemonFacts;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\ServiceLabels;
use App\Support\Files\FileJobs;
use App\Support\Fixes\FixRunner;
use App\Support\Health\JobAlerts;
use App\Support\Logs\LogIngest;
use App\Support\Logs\LogSkipRules;
use App\Support\Machines\CloudflareTunnel;
use App\Support\Machines\DaemonUpdateNotices;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\PackageUpdates;
use App\Support\Machines\StaleServices;
use App\Support\Projects\ProjectData;
use App\Support\Projects\ProjectLinks;
use App\Support\Projects\ProjectNetwork;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

use function Illuminate\Support\defer;

class DaemonGatewayController extends Controller
{
    private const FINISHED_JOB_STATES = ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];

    private const RESCAN_AFTER = ['deploy', 'rollback', 'service_remove', 'service_repair', 'service_action', 'service_clone', 'adopt'];

    private function authorised(Request $request): bool
    {
        $secret = config('daemon.gateway_secret');

        return is_string($secret) && $secret !== '' && hash_equals($secret, (string) $request->header('X-Gateway-Secret'));
    }

    public function hello(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'Gateway authentication failed.', 'code' => 'gateway_unauthorised'], 401);
        }
        $data = $request->validate([
            'serial' => ['required', 'string', 'regex:/^[a-f0-9]{1,40}$/'],
            'hello' => ['required', 'array'],
            'hello.machine_id' => ['required', 'uuid'],
            'hello.daemon_version' => ['required', 'string', 'max:80'],
            'hello.supported_majors' => ['required', 'array'],
            'hello.supported_majors.*' => ['integer'],
            'hello.facts' => ['required', 'array'],
            'hello.log_ip_masking' => ['nullable', 'string', 'in:off,partial,hash'],
            'hello.log_skip' => ['nullable', 'array'],
            'hello.security_watch' => ['nullable', 'boolean'],
            'hello.error_reports' => ['nullable', 'boolean'],
            'hello.discovery' => ['nullable', 'boolean'],
            'hello.logs' => ['nullable', 'boolean'],
            'hello.updates_automatic' => ['nullable', 'boolean'],
            'hello.pinned_version' => ['nullable', 'string', 'max:40'],
        ]);
        $machine = Machine::query()->where('certificate_serial', $data['serial'])->where('uid', $data['hello']['machine_id'])->first();
        if ($machine === null) {
            return response()->json(['error' => 'No machine enrolled with that certificate.', 'code' => 'certificate_unknown'], 403);
        }
        if (! in_array(1, $data['hello']['supported_majors'], true)) {
            return response()->json(['error' => 'The daemon protocol is unsupported.', 'code' => 'protocol_unsupported'], 422);
        }

        $versionBefore = $machine->daemon_version;
        $machine->forceFill([
            'name' => self::nameAfterHello($machine, $data['hello']['facts']),
            ...MachineMetadata::fromFacts($machine, $data['hello']['facts'], DaemonFacts::provider($data['hello']['facts'])),
            'daemon_version' => $data['hello']['daemon_version'],
            'facts' => $data['hello']['facts'],
            'ip_address' => DaemonFacts::address($data['hello']['facts'], $machine->ip_address),
            'last_seen_at' => now(),
            'connected_at' => now(),
            'disconnected_at' => null,
            ...(isset($data['hello']['log_skip']) ? ['log_skip' => LogSkipRules::fromDaemon($data['hello']['log_skip'])] : []),
        ])->save();

        if (is_string($versionBefore) && $versionBefore !== $machine->daemon_version) {
            app(DaemonUpdateNotices::class)->updated($machine, $versionBefore, $machine->daemon_version);
        }

        $reported = array_filter(
            array_intersect_key($data['hello'], array_flip(['discovery', 'logs', 'log_ip_masking', 'security_watch', 'error_reports', 'updates_automatic'])),
            fn ($value) => $value !== null,
        );
        if (array_key_exists('pinned_version', $request->input('hello', []))) {
            $reported['pinned_version'] = $data['hello']['pinned_version'] ?? null;
        }
        ApplyMachinePolicy::dispatch($machine->id, $reported)->delay(now()->addSeconds(5));

        return response()->json([
            'machine_id' => $machine->uid,
            'ack' => [
                'major' => 1,
                'panel_version' => config('app.name'),
                'last_seen_seq' => null,
                'mode' => config('daemon.commands_enabled') ? 'managed' : 'read_only',
            ],
        ]);
    }

    /** @param  array<mixed>  $facts */
    private static function nameAfterHello(Machine $machine, array $facts): string
    {
        $hostname = is_string($facts['hostname'] ?? null) ? trim($facts['hostname']) : '';
        $previous = $machine->facts['hostname'] ?? null;

        return $hostname !== '' && $machine->name === $previous ? $hostname : $machine->name;
    }

    public function ingest(Request $request, string $uid): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'Gateway authentication failed.', 'code' => 'gateway_unauthorised'], 401);
        }
        $machine = Machine::query()->where('uid', $uid)->first();
        if ($machine === null) {
            return response()->json(['error' => 'Machine not found.', 'code' => 'machine_unknown'], 404);
        }
        $data = $request->validate([
            'envelopes' => ['required', 'array', 'max:200'],
            'envelopes.*.v' => ['required', 'integer', 'in:1'],
            'envelopes.*.kind' => ['required', 'string', 'max:40'],
            'envelopes.*.payload' => ['required', 'array'],
        ]);

        $changes = ['last_seen_at' => now(), 'disconnected_at' => null];
        $firstInventory = $machine->inventory === null;
        foreach ($data['envelopes'] as $envelope) {
            $payload = $envelope['payload'];
            switch ($envelope['kind']) {
                case 'heartbeat':
                    if (isset($payload['daemon_version']) && is_string($payload['daemon_version'])) {
                        $changes['daemon_version'] = substr($payload['daemon_version'], 0, 80);
                    }
                    break;
                case 'logs':
                    LogIngest::store($machine, $payload);
                    break;
                case 'facts':
                    $changes['facts'] = $payload;
                    $changes = [...$changes, ...MachineMetadata::fromFacts($machine, $payload, DaemonFacts::provider($payload))];
                    $changes['ip_address'] = DaemonFacts::address($payload, $machine->ip_address);
                    break;
                case 'telemetry':
                    $services = [];
                    $readings = [];
                    foreach ($payload['samples'] ?? [] as $sample) {
                        if (! is_array($sample)) {
                            continue;
                        }
                        if (is_string($sample['service'] ?? null)) {
                            $services[substr($sample['service'], 0, 300)] = $this->serviceStat($sample);

                            continue;
                        }
                        if (isset($sample['cpu_percent']) && is_numeric($sample['cpu_percent'])) {
                            $changes['cpu_percent'] = max(0, min(100, (int) round((float) $sample['cpu_percent'])));
                        }
                        if (isset($sample['mem_used']) && is_numeric($sample['mem_used'])) {
                            $changes['memory_used_mb'] = max(0, (int) floor($sample['mem_used'] / 1048576));
                        }
                        if (isset($sample['disks']) && is_array($sample['disks'])) {
                            $disks = self::disks($sample['disks']);
                            $changes['disks'] = $disks;
                            $changes['disk_percent'] = self::fullest($disks);
                        }
                        $readings[] = $this->machineReading($sample);
                    }
                    MachineSample::record($machine, $readings);
                    if ($services !== []) {
                        $changes['service_stats'] = array_slice($services, 0, 200, true);
                        $this->recordServiceHistory($machine, $changes['service_stats']);

                        $statusPage = StatusPage::recordingFor($machine);
                        if ($statusPage !== null) {
                            $statusPage->recordSnapshot(array_keys($changes['service_stats']));
                        }
                    }
                    break;
                case 'inventory':
                    if (! isset($payload['services']) || ! is_array($payload['services']) || ! isset($payload['listeners']) || ! is_array($payload['listeners'])) {
                        return response()->json(['error' => 'Invalid inventory report.', 'code' => 'inventory_invalid'], 422);
                    }
                    if (($payload['scanned_at'] ?? 0) >= ($machine->inventory_scanned_at === null ? 0 : $machine->inventory_scanned_at->timestamp)) {
                        $changes['inventory'] = $payload;
                        $changes['inventory_scanned_at'] = now()->setTimestamp((int) ($payload['scanned_at'] ?? time()));
                        $changes['rescan_wanted_at'] = null;
                        $changes['services_count'] = ServiceLabels::shownCount($machine, $payload['services']);
                    }
                    break;
                case 'job_update':
                    $jobId = $payload['job_id'] ?? null;
                    $state = $payload['state'] ?? null;
                    $state = $state === 'accepted' ? 'queued' : $state;
                    if (! is_string($jobId) || ! is_string($state) || ! in_array($state, ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'refused'], true)) {
                        return response()->json(['error' => 'Invalid job update.', 'code' => 'job_update_invalid'], 422);
                    }
                    $job = DaemonJob::query()->where('machine_id', $machine->id)->whereKey($jobId)->first();
                    if ($job === null) {
                        return response()->json(['error' => 'Job not found for this machine.', 'code' => 'job_unknown'], 404);
                    }
                    if (in_array($job->state, self::FINISHED_JOB_STATES, true)) {
                        break;
                    }
                    $lines = is_array($payload['log'] ?? null) ? array_values(array_filter($payload['log'], 'is_string')) : [];
                    $log = array_slice([...($job->log ?? []), ...$lines], -500);
                    $job->forceFill([
                        'state' => $state,
                        'phase' => is_string($payload['phase'] ?? null) ? substr($payload['phase'], 0, 80) : $job->phase,
                        'progress' => is_numeric($payload['progress'] ?? null) ? max(0, min(100, (int) $payload['progress'])) : $job->progress,
                        'log' => $log,
                        'result' => is_array($payload['result'] ?? null) ? FileJobs::seal($job, $payload['result']) : $job->result,
                        'error' => is_array($payload['error'] ?? null) ? $payload['error'] : $job->error,
                        'finished_at' => in_array($state, self::FINISHED_JOB_STATES, true) ? now() : null,
                    ])->save();

                    if (in_array($state, self::FINISHED_JOB_STATES, true)) {
                        FileJobs::forgetContent($job);
                        $this->afterJob($job);
                        if ($state === 'succeeded' && in_array($job->kind, PackageUpdates::KINDS, true)) {
                            PackageUpdates::ingest($job);
                        }
                        StaleServices::ingest($job);
                    }

                    if ($job->kind === 'backup_to') {
                        BackupRunner::record($job);
                    } elseif ($job->kind === 'backup_receiver') {
                        BackupRunner::recordReceiver($job);
                    } elseif ($job->kind === 'restore') {
                        BackupRunner::recordRestore($job);
                    } elseif ($job->kind === 'deploy') {
                        BackupRunner::recordPreDeploy($job);
                    } elseif ($job->kind === 'rollback') {
                        BackupRunner::recordRollback($job);
                    }

                    if ($state === 'succeeded' && in_array($job->kind, self::RESCAN_AFTER, true)) {
                        $rescanFor = $job;
                    }

                    if (in_array($state, ['failed', 'timed_out'], true) && $job->kind === 'firewall_rule') {
                        $rescanFor = $job;
                    }

                    if ($state === 'succeeded' && $job->kind === 'service_remove' && is_string($job->service)) {
                        $inventory = $changes['inventory'] ?? $machine->inventory;
                        if (is_array($inventory)) {
                            $changes['inventory'] = $this->withoutService($inventory, $job->service);
                            $changes['services_count'] = ServiceLabels::shownCount($machine, $changes['inventory']['services'] ?? []);
                        }
                    }

                    if ($state === 'succeeded' && $job->kind === 'firewall_rule' && is_array($payload['result']['firewall'] ?? null)) {
                        $inventory = $changes['inventory'] ?? $machine->inventory;
                        if (is_array($inventory)) {
                            $changes['inventory'] = [...$inventory, 'firewall' => $payload['result']['firewall']];
                        }
                    }

                    if ($job->kind === CloudflareTunnel::KIND && in_array($state, self::FINISHED_JOB_STATES, true)) {
                        // The token has done its job: it doesn't stay in the panel.
                        $command = $job->command;
                        if (is_array($command) && isset($command['job']['token'])) {
                            unset($command['job']['token']);
                            $job->forceFill(['command' => $command])->saveQuietly();
                        }
                        if ($state === 'succeeded') {
                            $changes['tunnel'] = ($command['job']['action'] ?? null) === 'up'
                                ? CloudflareTunnel::fromResult(is_array($payload['result'] ?? null) ? $payload['result'] : [])
                                : null;
                        }
                    }

                    if ($state === 'succeeded' && $job->kind === 'adopt' && is_string($job->service)) {
                        $inventory = $changes['inventory'] ?? $machine->inventory;
                        if (is_array($inventory)) {
                            $changes['inventory'] = $this->withAdopted($inventory, $job->service);
                        }
                    }
                    break;
            }
        }
        $machine->forceFill($changes)->save();

        if (isset($rescanFor)) {
            $this->rescan($machine, $rescanFor);
        } elseif ($machine->rescan_wanted_at !== null) {
            $this->rescanIfStillWanted($machine);
        }

        if ($firstInventory && $machine->inventory !== null) {
            $this->startSetupScan($machine);
        }

        return response()->json(['ok' => true]);
    }

    private function rescan(Machine $machine, DaemonJob $after): void
    {
        $user = $after->user ?? $machine->workspace?->owner;
        $queue = app(DaemonJobQueue::class);

        if ($user === null || $queue->unavailable($machine) !== null) {
            $machine->forceFill(['rescan_wanted_at' => now()])->save();

            return;
        }

        try {
            $queue->queue($machine, $user, 'discover', ['type' => 'discover'], null, 120);
            if ($machine->rescan_wanted_at !== null) {
                $machine->forceFill(['rescan_wanted_at' => null])->save();
            }
        } catch (RuntimeException $e) {
            report($e);
            $machine->forceFill(['rescan_wanted_at' => now()])->save();
        }
    }

    private function rescanIfStillWanted(Machine $machine): void
    {
        $owner = $machine->workspace?->owner;
        $queue = app(DaemonJobQueue::class);
        if ($machine->rescan_wanted_at->lt(now()->subHour()) || $owner === null) {
            $machine->forceFill(['rescan_wanted_at' => null])->save();

            return;
        }
        if ($queue->unavailable($machine) !== null) {
            return;
        }

        try {
            $queue->queue($machine, $owner, 'discover', ['type' => 'discover'], null, 120);
            $machine->forceFill(['rescan_wanted_at' => null])->save();
        } catch (RuntimeException $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    private function withoutService(array $inventory, string $key): array
    {
        $services = array_values(array_filter($inventory['services'] ?? [], 'is_array'));
        $removed = collect($services)->firstWhere('key', $key);
        $project = is_array($removed) ? ($removed['details']['compose_project'] ?? null) : null;

        $inventory['services'] = array_values(array_filter($services, fn (array $service) => ($service['key'] ?? null) !== $key
            && ($project === null || ($service['details']['compose_project'] ?? null) !== $project)));

        return $inventory;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    private function withAdopted(array $inventory, string $key): array
    {
        $inventory['services'] = array_map(function ($service) use ($key) {
            if (is_array($service) && ($service['key'] ?? null) === $key) {
                $service['details'] = [...(is_array($service['details'] ?? null) ? $service['details'] : []), 'managed' => 'true'];
            }

            return $service;
        }, is_array($inventory['services'] ?? null) ? $inventory['services'] : []);

        return $inventory;
    }

    private function startSetupScan(Machine $machine): void
    {
        $owner = $machine->workspace?->owner;
        if ($owner === null || app(DaemonJobQueue::class)->unavailable($machine) !== null) {
            return;
        }

        try {
            app(DaemonJobQueue::class)->queue($machine, $owner, 'package_updates', [
                'type' => 'package_updates', 'apply' => false, 'security_only' => false,
            ], null, 180);
        } catch (RuntimeException) {
        }
    }

    /** @param  array<string, array<string, int|float|null>>  $services */
    private function recordServiceHistory(Machine $machine, array $services): void
    {
        if (! ServiceSample::due($machine)) {
            return;
        }

        $rows = [];
        foreach ($services as $key => $stat) {
            $rows[] = [
                'machine_id' => $machine->id,
                'service' => $key,
                'ts' => $stat['at'] ?? now()->getTimestamp(),
                'cpu' => $stat['cpu'],
                'mem_used' => $stat['memUsed'],
                'net_rx' => $stat['netRx'],
                'net_tx' => $stat['netTx'],
                'pids' => $stat['pids'],
            ];
        }

        ServiceSample::query()->insert($rows);
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array{ts: int, cpu: float|null, mem: int|null, disk: int|null}
     */
    private function machineReading(array $sample): array
    {
        $now = now()->getTimestamp();
        $ts = is_numeric($sample['ts'] ?? null) ? (int) $sample['ts'] : $now;
        $disks = is_array($sample['disks'] ?? null) ? self::disks($sample['disks']) : [];

        return [
            'ts' => $ts > $now ? $now : $ts,
            'cpu' => is_numeric($sample['cpu_percent'] ?? null) ? round(max(0, min(100, (float) $sample['cpu_percent'])), 2) : null,
            'mem' => is_numeric($sample['mem_used'] ?? null) ? max(0, (int) $sample['mem_used']) : null,
            'disk' => self::fullest($disks),
        ];
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, int|float|null>
     */
    private function serviceStat(array $sample): array
    {
        $int = fn (string $key) => is_numeric($sample[$key] ?? null) ? max(0, (int) $sample[$key]) : null;

        return [
            'cpu' => is_numeric($sample['cpu_percent'] ?? null) ? round(max(0, min(100, (float) $sample['cpu_percent'])), 2) : null,
            'memUsed' => $int('mem_used'),
            'memLimit' => $int('mem_total'),
            'pids' => $int('process_count'),
            'netRx' => $int('net_rx_bytes'),
            'netTx' => $int('net_tx_bytes'),
            'startedAt' => $int('started_at'),
            'restarts' => $int('restarts'),
            'at' => $int('ts'),
        ];
    }

    public function closed(Request $request, string $uid): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['error' => 'Gateway authentication failed.', 'code' => 'gateway_unauthorised'], 401);
        }
        $machine = Machine::query()->where('uid', $uid)->first();
        if ($machine === null) {
            return response()->json(['error' => 'Machine not found.', 'code' => 'machine_unknown'], 404);
        }
        $machine->forceFill(['disconnected_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    private function afterJob(DaemonJob $job): void
    {
        defer(function () use ($job) {
            app(DaemonJobQueue::class)->followUp($job);
            if ($job->kind === 'network') {
                app(ProjectNetwork::class)->jobFinished($job);
            }
            if ($job->kind === 'deploy') {
                app(ProjectLinks::class)->deployFinished($job);
                app(ProjectData::class)->deployFinished($job);
            }
            if ($job->kind === 'service_exec') {
                app(ProjectData::class)->jobFinished($job);
            }
            if ($job->workflow_run_id !== null) {
                app(WorkflowEngine::class)->jobFinished($job);
            }
            if ($job->agent_fix_id !== null) {
                app(FixRunner::class)->jobFinished($job);
            }
            if ($job->kind === 'deploy' && is_string($job->service)) {
                $machine = $job->machine;
                $outcome = $job->state === 'succeeded' ? 'succeeded' : 'failed';
                app(WorkflowEvents::class)->dispatch($machine->workspace_id, 'deploy.finished',
                    "{$job->service} on {$machine->name} ".($outcome === 'succeeded' ? 'deployed' : 'failed to deploy'),
                    ['machine_id' => $machine->id, 'machine' => $machine->name, 'service' => $job->service, 'outcome' => $outcome, 'job_id' => $job->id]);
                app(JobAlerts::class)->deployFinished($job);
            }
        });
    }

    /** @param  list<array{mount: string, used: int, total: int}>  $disks */
    private static function fullest(array $disks): ?int
    {
        return $disks === [] ? null : max(array_map(fn (array $d) => (int) floor($d['used'] / $d['total'] * 100), $disks));
    }

    /**
     * @param  array<int, mixed>  $samples
     * @return list<array{mount: string, used: int, total: int}>
     */
    private static function disks(array $samples): array
    {
        $disks = [];
        foreach (array_slice($samples, 0, 64) as $disk) {
            if (! is_array($disk) || ! is_string($disk['mount'] ?? null) || ! is_numeric($disk['used_bytes'] ?? null) || ! is_numeric($disk['free_bytes'] ?? null)) {
                continue;
            }
            $mount = substr($disk['mount'], 0, 200);
            $used = (int) $disk['used_bytes'];
            $total = $used + (int) $disk['free_bytes'];
            if ($total < 1 << 30 || preg_match('#^/(snap|boot/efi|run|dev|sys|proc)(/|$)#', $mount) === 1) {
                continue;
            }
            $disks[$mount] = ['mount' => $mount, 'used' => $used, 'total' => $total];
        }
        usort($disks, fn (array $a, array $b) => $b['used'] / $b['total'] <=> $a['used'] / $a['total']);

        return array_slice($disks, 0, 8);
    }
}
