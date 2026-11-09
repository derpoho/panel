<?php

namespace App\Support\Machines;

use App\Mcp\McpContext;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Health\WorkspaceHealth;

/**
 * What's using a machine's disk (the daemon's read-only disk_usage job),
 * with suggestions for what's safe to clear. Nothing is deleted from here:
 * the person runs the commands, or asks.
 */
final class DiskUsage
{
    /** Longest the daemon's scan takes, plus room to report back. */
    public const TIMEOUT = 120;

    /** Why it can't be scanned now, or null when it can. */
    public static function unavailable(Machine $machine): ?string
    {
        if (! DaemonJobQueue::canScanDisk($machine)) {
            return 'Its ServerOS daemon is too old to say what’s using the disk. Update it from the machine’s page first.';
        }

        return app(DaemonJobQueue::class)->unavailable($machine);
    }

    /** The newest scan of the machine's disk, if any. */
    public static function latest(Machine $machine): ?DaemonJob
    {
        return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'disk_usage')->latest()->first();
    }

    /**
     * A scan as the machine page and the MCP tool show it.
     *
     * @return array<string, mixed>
     */
    public static function present(DaemonJob $job): array
    {
        $result = is_array($job->result) ? $job->result : [];
        $dirs = array_values(array_filter(
            is_array($result['dirs'] ?? null) ? $result['dirs'] : [],
            fn ($dir) => is_array($dir) && is_string($dir['path'] ?? null) && is_int($dir['bytes'] ?? null),
        ));
        $docker = array_values(array_filter(is_array($result['docker'] ?? null) ? $result['docker'] : [], 'is_array'));

        return [
            'jobId' => $job->id,
            'state' => $job->isStale() ? 'stale' : $job->state,
            'finished' => in_array($job->state, McpContext::FINISHED, true),
            'error' => $job->error,
            'scannedAt' => $job->finished_at?->toIso8601String(),
            'path' => $result['path'] ?? null,
            'total' => $result['total'] ?? null,
            'used' => $result['used'] ?? null,
            'available' => $result['available'] ?? null,
            'partial' => (bool) ($result['partial'] ?? false),
            'dirs' => array_map(fn (array $dir) => ['path' => $dir['path'], 'bytes' => $dir['bytes']], $dirs),
            'journal' => is_int($result['journal'] ?? null) ? $result['journal'] : null,
            'docker' => $docker,
            'suggestions' => $result === [] ? [] : self::suggestions($dirs, $result['journal'] ?? null, $docker),
        ];
    }

    /**
     * What's worth clearing, biggest wins first: each with what it is and,
     * where one command does it, that command.
     *
     * @param  list<array{path: string, bytes: int}>  $dirs
     * @param  list<array<string, mixed>>  $docker
     * @return list<array{title: string, detail: string, command: string|null}>
     */
    private static function suggestions(array $dirs, mixed $journal, array $docker): array
    {
        $size = fn (string $path) => collect($dirs)->firstWhere('path', $path)['bytes'] ?? 0;
        $out = [];

        if (is_int($journal) && $journal >= 500 * 1024 * 1024) {
            $out[] = ['title' => __('The system journal takes :size', ['size' => WorkspaceHealth::bytes($journal)]),
                'detail' => __('Old journal entries are safe to drop. This keeps the newest 200 MB.'),
                'command' => 'journalctl --vacuum-size=200M'];
        }

        $reclaim = collect($docker)
            ->filter(fn (array $row) => is_string($row['reclaimable'] ?? null) && ! str_starts_with($row['reclaimable'], '0B'))
            ->map(fn (array $row) => "{$row['kind']}: ".preg_replace('/\s*\(.*$/', '', $row['reclaimable']))
            ->values();
        if ($reclaim->isNotEmpty()) {
            $out[] = ['title' => __('Docker could reclaim space'),
                'detail' => __(':list. This removes images no container uses, stopped containers, unused networks and the build cache. Volumes are kept.', ['list' => $reclaim->implode(', ')]),
                'command' => 'docker system prune -a'];
        }

        if ($size('/var/cache/apt') >= 200 * 1024 * 1024) {
            $out[] = ['title' => __('The package cache takes :size', ['size' => WorkspaceHealth::bytes($size('/var/cache/apt'))]),
                'detail' => __('Downloaded packages that are already installed. Safe to clear.'),
                'command' => 'apt-get clean'];
        }

        foreach (['/var/lib/postgresql' => 'Postgres', '/var/lib/mysql' => 'MySQL', '/var/lib/mongodb' => 'MongoDB', '/var/lib/docker/volumes' => 'Docker volumes'] as $path => $what) {
            if ($size($path) >= 1024 * 1024 * 1024) {
                $out[] = ['title' => __(':what data takes :size', ['what' => $what, 'size' => WorkspaceHealth::bytes($size($path))]),
                    'detail' => __('Usually a table that grows without limit, like logs or events. Find it and prune old rows rather than deleting files here.'),
                    'command' => null];
            }
        }

        $logs = $size('/var/log') - (is_int($journal) ? $journal : 0);
        if ($logs >= 1024 * 1024 * 1024) {
            $out[] = ['title' => __('Log files in /var/log take :size', ['size' => WorkspaceHealth::bytes($logs)]),
                'detail' => __('Check logrotate covers whatever writes them, and remove old rotated files (*.gz, *.1).'),
                'command' => null];
        }

        return $out;
    }
}
