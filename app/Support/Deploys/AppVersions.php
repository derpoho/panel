<?php

namespace App\Support\Deploys;

use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\Backups\BackupRunner;
use App\Support\DaemonJobQueue;
use RuntimeException;

/**
 * An app's versions on a machine, from its deploys: which is live, which
 * the machine still keeps to roll back to, and what data can go back with
 * one. Rolling back to a version restores, if asked, the snapshot taken
 * just before the deploy that replaced it.
 */
final class AppVersions
{
    /** Versions the daemon keeps per app (daemon-deploy's RETAINED_RELEASES). */
    public const int KEPT = 5;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * Newest first: each version's deploy, whether it's live, and the
     * snapshots to restore when rolling back to it (empty when there are
     * none, or the machine no longer keeps them).
     *
     * @return list<array{job: DaemonJob, commit: string, live: bool, restore: list<array{service: string, snapshot: string}>, dataAt: string|null}>
     */
    public static function for(Machine $machine, string $app): array
    {
        $deploys = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $app)
            ->where('state', 'succeeded')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (DaemonJob $job) => filled($job->command['job']['repo'] ?? null) && filled($job->command['job']['commit'] ?? null))
            ->values();
        $live = AppReleases::live($machine)[$app] ?? null;

        $versions = [];
        foreach ($deploys as $index => $job) {
            $commit = (string) $job->command['job']['commit'];
            if (collect($versions)->contains(fn (array $v) => $v['commit'] === $commit)) {
                continue;
            }
            if (count($versions) >= self::KEPT) {
                break;
            }

            // The deploy that replaced this version snapshotted its data.
            $next = $deploys->slice(0, $index)->last();
            $restore = $next === null ? [] : self::keptSnapshots($machine, $next, $commit);

            $versions[] = [
                'job' => $job,
                'commit' => $commit,
                'live' => $live !== null && str_starts_with($commit, $live),
                'restore' => $restore,
                'dataAt' => $restore === [] ? null : $next->created_at?->toIso8601String(),
            ];
        }

        return $versions;
    }

    /**
     * The snapshots a deploy took of this version's containers, while the
     * machine still keeps them.
     *
     * @return list<array{service: string, snapshot: string}>
     */
    private static function keptSnapshots(Machine $machine, DaemonJob $deploy, string $commit): array
    {
        $also = (array) ($deploy->command['job']['snapshot_also'] ?? []);
        $snapshots = $deploy->result['pre_deploy_snapshots'] ?? [];
        $taken = collect(is_array($snapshots) ? $snapshots : [])
            ->filter(fn ($s) => is_array($s) && is_string($s['service'] ?? null) && is_string($s['snapshot'] ?? null))
            // A version built from code runs as serveros-<app>-<commit>; its
            // database was snapshotted with it.
            ->filter(fn (array $s) => str_ends_with((string) ($s['name'] ?? ''), '-'.substr($commit, 0, 12))
                || in_array($s['service'], $also, true));

        $restore = [];
        foreach ($taken as $snapshot) {
            $row = Backup::query()->where('machine_id', $machine->id)->where('trigger', 'pre-deploy')
                ->where('service', $snapshot['service'])->where('snapshot', $snapshot['snapshot'])->first();
            if ($row === null || ! in_array($row->id, BackupRunner::keptOnMachine($machine->id, $row->service, true), true)) {
                return [];
            }
            // By key, in case it's been recreated.
            $restore[] = ['service' => BackupRunner::currentKey($row), 'snapshot' => $snapshot['snapshot']];
        }

        return $restore;
    }

    /**
     * Switch an app back to an earlier version, and its data too if asked.
     *
     * @throws RuntimeException when that can't be done now
     */
    public function rollback(Machine $machine, User $user, string $app, string $commit, bool $withData): DaemonJob
    {
        $version = collect(self::for($machine, $app))->first(fn (array $v) => $v['commit'] === $commit);

        $reason = match (true) {
            $version === null => 'That version isn’t kept on '.$machine->name.' any more. It keeps the last '.self::KEPT.'.',
            $version['live'] => 'That version is already live.',
            $withData && $version['restore'] === [] => 'There’s no snapshot of its data to put back. Roll back the code only, or restore a backup.',
            default => $this->queue->unavailable($machine, 'rollback'),
        };
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return $this->queue->queue($machine, $user, 'rollback', [
            'type' => 'rollback',
            'service' => $app,
            'release' => $commit,
            'restore' => $withData ? $version['restore'] : [],
        ], $app, 1800);
    }
}
