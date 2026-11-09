<?php

namespace App\Support\Health;

use App\Models\Backup;
use App\Models\DaemonJob;
use App\Support\Deploys\DeployFailure;
use App\Support\Monitors\MonitorAlerts;

/**
 * Alerts about work that ran without anyone watching: a backup or a deploy
 * (often from CI or a schedule) that failed. Once when it starts failing,
 * not for every failure in a row, and once more when the next one works.
 * Whether the last one failed comes from the backups and jobs themselves,
 * so there's no state to keep. Machines under planned work stay quiet.
 */
final class JobAlerts
{
    /** Job states that count as a deploy that didn't work. */
    private const DEPLOY_FAILED = ['failed', 'timed_out', 'refused'];

    public function __construct(private readonly MonitorAlerts $alerts) {}

    public function backupFinished(Backup $backup): void
    {
        if (! in_array($backup->state, ['succeeded', 'failed'], true) || $backup->machine->inMaintenance()) {
            return;
        }

        $previous = Backup::query()
            ->where('machine_id', $backup->machine_id)
            ->where('service', $backup->service)
            ->whereIn('state', ['succeeded', 'failed'])
            ->where('id', '<', $backup->id)
            ->latest('id')
            ->value('state');

        $machine = $backup->machine;
        $url = 'https://'.$machine->workspace->host().'/backups';
        $extra = ['machine' => ['id' => $machine->id, 'name' => $machine->name], 'service' => $backup->service_name];

        if ($backup->state === 'failed' && $previous !== 'failed') {
            $this->alerts->notify($machine->workspace, 'backup.failed', "{$backup->service_name} on {$machine->name} couldn’t be backed up", [
                $backup->error ?: 'The backup failed.',
                'Often the storage’s credentials, space or reachability. Fix that, then run a backup again from Backups.',
            ], $url, true, 'Open backups', $extra);
        } elseif ($backup->state === 'succeeded' && $previous === 'failed') {
            $this->alerts->notify($machine->workspace, 'backup.recovered', "{$backup->service_name} on {$machine->name} is backing up again", [
                'The latest backup worked.',
            ], $url, false, 'Open backups', $extra);
        }
    }

    public function deployFinished(DaemonJob $job): void
    {
        $failed = in_array($job->state, self::DEPLOY_FAILED, true);
        if ($job->kind !== 'deploy' || ! is_string($job->service) || (! $failed && $job->state !== 'succeeded') || $job->machine->inMaintenance()) {
            return;
        }

        $previous = DaemonJob::query()
            ->where('machine_id', $job->machine_id)
            ->where('kind', 'deploy')
            ->where('service', $job->service)
            ->whereIn('state', ['succeeded', ...self::DEPLOY_FAILED])
            ->whereKeyNot($job->id)
            ->where('created_at', '<=', $job->created_at)
            ->latest('created_at')
            ->value('state');
        $previousFailed = in_array($previous, self::DEPLOY_FAILED, true);

        $machine = $job->machine;
        $url = 'https://'.$machine->workspace->host().'/machines/'.$machine->id;
        $extra = ['machine' => ['id' => $machine->id, 'name' => $machine->name], 'service' => $job->service, 'job_id' => $job->id];

        if ($failed && ! $previousFailed) {
            $error = is_array($job->error) ? trim((string) ($job->error['message'] ?? '')) : '';
            $why = DeployFailure::explain($job);
            $this->alerts->notify($machine->workspace, 'deploy.failed', "{$job->service} on {$machine->name} failed to deploy", array_values(array_filter($why !== null ? [
                $why['cause'],
                trim($why['fix'].(isset($why['command']) ? ' '.$why['command'] : '')),
            ] : [
                $job->state === 'timed_out' ? 'The deploy took too long and was stopped.' : ($error !== '' ? $error : 'The deploy failed.'),
                'If an earlier version was running, it still is. The deploy’s log shows where it stopped.',
            ])), $url.'?job='.$job->id, true, 'Open the deploy', $extra);
        } elseif (! $failed && $previousFailed) {
            $this->alerts->notify($machine->workspace, 'deploy.recovered', "{$job->service} on {$machine->name} deployed", [
                'The latest deploy worked, after the last one failed.',
            ], $url, false, 'Open the machine', $extra);
        }
    }
}
