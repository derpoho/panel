<?php

namespace App\Support\Backups;

use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Health\JobAlerts;
use RuntimeException;

/**
 * Backs up a machine's managed services to its destination: one daemon
 * job per service, each recorded as a Backup the gateway updates.
 */
class BackupRunner
{
    /** A big game world can take a while to tar and send. */
    private const int TIMEOUT_SECS = 6 * 3600;

    /**
     * Snapshots the daemon keeps on the machine for each service
     * (daemon-backup's DEFAULT_RETENTION). Older backups are only at the
     * destination, and can't be restored from there yet.
     */
    public const int KEPT_ON_MACHINE = 7;

    /** Snapshots taken before deploys, kept apart from those (PRE_DEPLOY_RETENTION). */
    public const int PRE_DEPLOYS_KEPT = 3;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * Why this machine can't be backed up right now, or null if it can.
     */
    public function unavailable(Machine $machine): ?string
    {
        $destination = $machine->backupDestination;

        return match (true) {
            $destination === null => 'Choose where its backups go first.',
            $destination->status !== 'ready' => "{$destination->name} isn't ready yet.",
            $destination->machine_id === $machine->id => 'A machine can\'t keep its own backups; pick somewhere else.',
            $machine->backupableServices() === [] => self::nothingToBackUp($machine),
            default => $this->queue->unavailable($machine),
        };
    }

    /** Why a machine has nothing to back up: nothing managed, or nothing with data. */
    private static function nothingToBackUp(Machine $machine): string
    {
        $managed = array_filter($machine->inventory['services'] ?? [], fn ($s) => is_array($s) && ($s['details']['managed'] ?? null) === 'true');

        return $managed === []
            ? 'Nothing on it is managed by ServerOS yet. Deploy or adopt a service first.'
            : 'Nothing ServerOS manages on it has data to back up. Deploy or adopt an app or database first.';
    }

    /**
     * Queue a backup of every managed service. Returns how many started.
     *
     * @throws RuntimeException when the machine can't be backed up now
     */
    public function run(Machine $machine, User $user, string $trigger): int
    {
        if (($reason = $this->unavailable($machine)) !== null) {
            throw new RuntimeException($reason);
        }

        /** @var BackupDestination $destination */
        $destination = $machine->backupDestination;
        $prefix = trim(($destination->settings['prefix'] ?? '').'/'.$machine->workspace->slug.'/'.$machine->name, '/');
        $started = 0;

        foreach ($machine->backupableServices() as $service) {
            $backup = $machine->backups()->create([
                'backup_destination_id' => $destination->id,
                'service' => $service['key'],
                'service_name' => $service['name'] ?? $service['key'],
                'trigger' => $trigger,
                'state' => 'queued',
            ]);

            try {
                $job = $this->queue->queue($machine, $user, 'backup_to', [
                    'type' => 'backup_to',
                    'service' => $service['key'],
                    'reason' => $trigger,
                    'prefix' => $prefix,
                    'destination' => $destination->forDaemon(),
                ], $service['key'], self::TIMEOUT_SECS);
                $backup->forceFill(['daemon_job_id' => $job->id])->save();
                $started++;
            } catch (RuntimeException $e) {
                $backup->forceFill(['state' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()])->save();
                app(JobAlerts::class)->backupFinished($backup);
            }
        }

        return $started;
    }

    /**
     * A backup job's update from the daemon. Called by the gateway ingest.
     */
    public static function record(DaemonJob $job): void
    {
        $backup = Backup::query()->where('daemon_job_id', $job->id)->first();
        if ($backup === null) {
            return;
        }

        $error = is_array($job->error) ? (string) ($job->error['message'] ?? '') : '';
        $state = match ($job->state) {
            'queued', 'running' => $job->state,
            'succeeded' => 'succeeded',
            // Nothing outside the container to keep, or a service with no
            // data the daemon knows how to copy (Docker itself, a proxy):
            // not a failure.
            default => str_contains($error, 'keeps no data outside its container')
                || str_contains($error, 'does not know how to snapshot') ? 'skipped' : 'failed',
        };

        $newlyFailed = $state === 'failed' && $backup->state !== 'failed';
        $newlyFinished = in_array($state, ['succeeded', 'failed'], true) && $backup->state !== $state;

        $backup->forceFill([
            'state' => $state,
            'snapshot' => is_string($job->result['snapshot'] ?? null) ? mb_substr($job->result['snapshot'], 0, 80) : $backup->snapshot,
            'bytes' => is_numeric($job->result['bytes'] ?? null) ? (int) $job->result['bytes'] : $backup->bytes,
            'location' => is_string($job->result['location'] ?? null) ? substr($job->result['location'], 0, 1000) : $backup->location,
            'error' => $state === 'failed' ? substr($error ?: 'The backup failed.', 0, 1000) : null,
            'finished_at' => in_array($state, ['succeeded', 'failed', 'skipped'], true) ? now() : null,
        ])->save();

        if ($state === 'succeeded') {
            $backup->machine->forceFill(['last_backup_at' => now()])->save();
        }
        if ($newlyFailed) {
            Activity::system($backup->machine->workspace_id, 'backup.failed', $backup->service_name, $backup->machine, ['error' => $backup->error]);
        }
        if ($newlyFinished) {
            app(JobAlerts::class)->backupFinished($backup);
        }
    }

    /**
     * Why this backup can't be restored now, or null if it can. Only the
     * newest few are still on the machine.
     */
    public function cannotRestore(Backup $backup): ?string
    {
        $machine = $backup->machine;

        return match (true) {
            $backup->state !== 'succeeded' || $backup->snapshot === null => 'Only a backup that finished can be restored.',
            DaemonJob::query()->where('machine_id', $backup->machine_id)->where('kind', 'restore')->where('service', $backup->service)
                ->whereIn('state', ['queued', 'running'])->exists() => "{$backup->service_name} is being restored now. Wait for it to finish.",
            ! in_array($backup->id, self::keptOnMachine($backup->machine_id, $backup->service, $backup->trigger === 'pre-deploy'), true) => self::notKept($backup),
            default => $this->queue->unavailable($machine),
        };
    }

    /**
     * Why each of these backups can't be restored now (null where it can),
     * worked out together so a page of history costs a few queries.
     *
     * @param  iterable<Backup>  $backups
     * @return array<int, string|null> by backup id
     */
    public function cannotRestoreAll(iterable $backups): array
    {
        [$kept, $busy, $offline, $reasons] = [[], null, [], []];

        foreach ($backups as $backup) {
            if ($backup->state !== 'succeeded' || $backup->snapshot === null) {
                $reasons[$backup->id] = 'Only a backup that finished can be restored.';

                continue;
            }
            $key = $backup->machine_id.'|'.$backup->service;
            $group = $key.'|'.($backup->trigger === 'pre-deploy' ? 'deploy' : 'backup');
            $kept[$group] ??= self::keptOnMachine($backup->machine_id, $backup->service, $backup->trigger === 'pre-deploy');
            $busy ??= DaemonJob::query()->where('kind', 'restore')->whereIn('state', ['queued', 'running'])
                ->get(['machine_id', 'service'])->map(fn (DaemonJob $j) => $j->machine_id.'|'.$j->service)->all();
            $offline[$backup->machine_id] ??= $this->queue->unavailable($backup->machine) ?? false;

            $reasons[$backup->id] = match (true) {
                in_array($key, $busy, true) => "{$backup->service_name} is being restored now. Wait for it to finish.",
                ! in_array($backup->id, $kept[$group], true) => self::notKept($backup),
                $offline[$backup->machine_id] !== false => $offline[$backup->machine_id],
                default => null,
            };
        }

        return $reasons;
    }

    /**
     * The backups of a service still on its machine: the newest few with a
     * snapshot. Snapshots taken before deploys are kept apart, as the
     * machine keeps them.
     *
     * @return list<int>
     */
    public static function keptOnMachine(int $machineId, string $service, bool $preDeploy = false): array
    {
        return array_values(Backup::query()
            ->where('machine_id', $machineId)
            ->where('service', $service)
            ->where('state', 'succeeded')
            ->whereNotNull('snapshot')
            ->when($preDeploy, fn ($q) => $q->where('trigger', 'pre-deploy'), fn ($q) => $q->where('trigger', '!=', 'pre-deploy'))
            ->latest('id')
            ->limit($preDeploy ? self::PRE_DEPLOYS_KEPT : self::KEPT_ON_MACHINE)
            ->get(['id'])
            ->map(fn (Backup $backup) => $backup->id)
            ->all());
    }

    private static function notKept(Backup $backup): string
    {
        return $backup->trigger === 'pre-deploy'
            ? "It’s no longer kept on {$backup->machine->name}, which keeps the snapshots from the last ".self::PRE_DEPLOYS_KEPT.' deploys.'
            : "It’s no longer kept on {$backup->machine->name}, which keeps the last ".self::KEPT_ON_MACHINE.' per service. Restoring from the destination isn’t supported yet.';
    }

    /**
     * A deploy finished: the snapshots it took of what it replaced become
     * backups in the history, so they can be restored like any other.
     */
    public static function recordPreDeploy(DaemonJob $job): void
    {
        if ($job->state !== 'succeeded') {
            return;
        }

        foreach ($job->result['pre_deploy_snapshots'] ?? [] as $snapshot) {
            if (! is_array($snapshot) || ! is_string($snapshot['service'] ?? null) || ! is_string($snapshot['snapshot'] ?? null)) {
                continue;
            }
            if (Backup::query()->where('daemon_job_id', $job->id)->where('service', $snapshot['service'])->exists()) {
                continue;
            }
            $job->machine->backups()->create([
                'daemon_job_id' => $job->id,
                'service' => $snapshot['service'],
                'service_name' => mb_substr((string) ($snapshot['name'] ?? $snapshot['service']), 0, 255),
                'trigger' => 'pre-deploy',
                'state' => 'succeeded',
            ])->forceFill([
                'snapshot' => mb_substr($snapshot['snapshot'], 0, 80),
                'bytes' => is_numeric($snapshot['bytes'] ?? null) ? (int) $snapshot['bytes'] : null,
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * A rollback put data back: the snapshot taken before each restore
     * becomes a backup, so restoring it undoes that part.
     */
    public static function recordRollback(DaemonJob $job): void
    {
        if ($job->state !== 'succeeded') {
            return;
        }

        foreach ($job->result['restored'] ?? [] as $restored) {
            $safety = is_array($restored) ? ($restored['pre_restore_snapshot'] ?? null) : null;
            if (! is_string($safety) || ! is_string($restored['service'] ?? null)
                || Backup::query()->where('machine_id', $job->machine_id)->where('snapshot', $safety)->exists()) {
                continue;
            }
            $named = Backup::query()->where('machine_id', $job->machine_id)->where('service', $restored['service'])->latest('id')->value('service_name');
            $job->machine->backups()->create([
                'service' => $restored['service'],
                'service_name' => $named ?? $restored['service'],
                'trigger' => 'pre-restore',
                'state' => 'succeeded',
            ])->forceFill(['snapshot' => mb_substr($safety, 0, 80), 'finished_at' => now()])->save();
        }
    }

    /**
     * The service's key now. A container that's been recreated (a Compose
     * app redeployed, say) has a new id, so its key changes; its name, and
     * the snapshots kept under it, don't.
     */
    public static function currentKey(Backup $backup): string
    {
        $services = array_filter($backup->machine->inventory['services'] ?? [], 'is_array');
        if (! str_starts_with($backup->service, 'docker:') || collect($services)->contains('key', $backup->service)) {
            return $backup->service;
        }

        $byName = collect($services)->first(fn (array $s) => str_starts_with((string) ($s['key'] ?? ''), 'docker:')
            && ltrim((string) ($s['name'] ?? ''), '/') === ltrim($backup->service_name, '/'));

        return is_string($byName['key'] ?? null) ? $byName['key'] : $backup->service;
    }

    /**
     * Put a service's data back as it was in this backup. The daemon takes
     * a snapshot first, which shows in the history so the restore can be
     * undone.
     *
     * @throws RuntimeException when it can't be restored now
     */
    public function restore(Backup $backup, User $user): DaemonJob
    {
        if (($reason = $this->cannotRestore($backup)) !== null) {
            throw new RuntimeException($reason);
        }

        $service = self::currentKey($backup);
        $job = $this->queue->queue($backup->machine, $user, 'restore', [
            'type' => 'restore',
            'service' => $service,
            'snapshot' => $backup->snapshot,
        ], $service, self::TIMEOUT_SECS);

        $backup->forceFill(['restore_job_id' => $job->id, 'restore_state' => 'queued', 'restore_error' => null, 'restored_at' => null])->save();

        return $job;
    }

    /**
     * A restore job's update from the daemon. Called by the gateway ingest.
     * Once it's done, the snapshot taken before it becomes a backup of its
     * own, so restoring that undoes it.
     */
    public static function recordRestore(DaemonJob $job): void
    {
        $backup = Backup::query()->where('restore_job_id', $job->id)->first();
        if ($backup === null) {
            return;
        }

        $state = match ($job->state) {
            'queued', 'running', 'succeeded' => $job->state,
            default => 'failed',
        };
        $error = is_array($job->error) ? (string) ($job->error['message'] ?? '') : '';
        if ($state === 'failed' && str_contains($error, 'not found')) {
            $error = "This backup is no longer kept on {$backup->machine->name}. Restoring from the destination isn’t supported yet.";
        }
        $wasRunning = in_array($backup->restore_state, ['queued', 'running'], true);

        $backup->forceFill([
            'restore_state' => $state,
            'restore_error' => $state === 'failed' ? mb_substr($error ?: 'The restore failed.', 0, 1000) : null,
            'restored_at' => $state === 'succeeded' ? now() : $backup->restored_at,
        ])->save();

        if (! $wasRunning) {
            return;
        }

        $safety = $job->result['pre_restore_snapshot'] ?? null;
        if ($state === 'succeeded' && is_string($safety) && $safety !== '') {
            $backup->machine->backups()->create([
                // The key it was restored under, which is current.
                'service' => is_string($job->service) ? $job->service : $backup->service,
                'service_name' => $backup->service_name,
                'trigger' => 'pre-restore',
                'state' => 'succeeded',
            ])->forceFill(['snapshot' => mb_substr($safety, 0, 80), 'finished_at' => now()])->save();
        }
        if ($state === 'failed') {
            Activity::system($backup->machine->workspace_id, 'backup.restore_failed', $backup->service_name, $backup->machine, ['error' => $backup->restore_error]);
        }
    }

    /**
     * The receiving machine's setup job finished.
     */
    public static function recordReceiver(DaemonJob $job): void
    {
        $destination = BackupDestination::query()
            ->where('type', 'machine')
            ->where('machine_id', $job->machine_id)
            ->where('status', 'setting_up')
            ->get()
            ->first(fn (BackupDestination $d) => $d->public_key !== null && ($job->command['job']['public_key'] ?? null) === $d->public_key);
        if ($destination === null || in_array($job->state, ['queued', 'running'], true)) {
            return;
        }

        $destination->forceFill($job->state === 'succeeded'
            ? ['status' => 'ready', 'status_message' => null, 'host_key' => is_string($job->result['host_key'] ?? null) ? $job->result['host_key'] : null]
            : ['status' => 'failed', 'status_message' => substr((string) ($job->error['message'] ?? 'Setting up the machine failed.'), 0, 500)],
        )->save();
    }
}
