<?php

namespace App\Support\Logs;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Lines per machine, service, minute and level (log_counts), kept as lines
 * arrive. The Logs page's chart reads these: counting log_entries for a day
 * or a week would read millions of rows on every page load.
 */
final class LogCounts
{
    public const MINUTE = 60_000;

    /** Bucket sizes in minutes, smallest first; the chart uses the first that fits. */
    private const BUCKETS = [1, 2, 5, 10, 15, 30, 60, 120, 180, 360, 720, 1440];

    /** Most bars the chart draws. */
    private const MAX_BARS = 90;

    /** @param  list<array{machine_id: int, service: string, logged_at: int, level: string}>  $rows */
    public static function add(array $rows, ?string $connection = null): void
    {
        $counts = [];
        foreach ($rows as $row) {
            $minute = $row['logged_at'] - ($row['logged_at'] % self::MINUTE);
            $key = $row['machine_id'].'|'.$minute.'|'.$row['service'].'|'.$row['level'];
            $counts[$key] ??= [$row['machine_id'], $minute, $row['service'], $row['level'], 0];
            $counts[$key][4]++;
        }

        $db = DB::connection($connection ?? LogStore::ours());
        foreach (array_chunk(array_values($counts), 200) as $chunk) {
            $db->statement(
                'insert into log_counts (machine_id, minute, service, level, n) values '
                .implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
                .(self::mysql($db)
                    ? ' on duplicate key update n = log_counts.n + values(n)'
                    : ' on conflict (machine_id, minute, service, level) do update set n = log_counts.n + excluded.n'),
                array_merge(...$chunk),
            );
        }
    }

    /**
     * Take deleted lines off the counts. A minute left with none goes, so
     * the chart doesn't keep showing lines that are gone.
     *
     * @param  list<array{machine_id: int, service: string, logged_at: int, level: string}>  $rows
     */
    public static function subtract(array $rows, ?string $connection = null): void
    {
        $counts = [];
        foreach ($rows as $row) {
            $minute = $row['logged_at'] - ($row['logged_at'] % self::MINUTE);
            $key = $row['machine_id'].'|'.$minute.'|'.$row['service'].'|'.$row['level'];
            $counts[$key] ??= ['machine_id' => $row['machine_id'], 'minute' => $minute, 'service' => $row['service'], 'level' => $row['level'], 'n' => 0];
            $counts[$key]['n']++;
        }

        foreach ($counts as $count) {
            $row = DB::connection($connection ?? LogStore::ours())->table('log_counts')->where('machine_id', $count['machine_id'])->where('minute', $count['minute'])
                ->where('service', $count['service'])->where('level', $count['level']);
            // Delete first: decrementing first would leave small counts that
            // then look emptied.
            $row->clone()->where('n', '<=', $count['n'])->delete();
            $row->clone()->where('n', '>', $count['n'])->decrement('n', $count['n']);
        }
    }

    /**
     * Recount every machine's lines between two times from log_entries,
     * replacing what's there.
     */
    public static function recount(int $fromMs, int $untilMs, ?string $connection = null): void
    {
        $db = DB::connection($connection ?? LogStore::ours());
        $db->statement(
            'insert into log_counts (machine_id, minute, service, level, n)
            select machine_id, logged_at - (logged_at % '.self::MINUTE.'), service, level, count(*)
            from log_entries
            where logged_at >= ? and logged_at < ?
            group by machine_id, logged_at - (logged_at % '.self::MINUTE.'), service, level
            '.(self::mysql($db) ? 'on duplicate key update n = values(n)' : 'on conflict (machine_id, minute, service, level) do update set n = excluded.n'),
            [$fromMs, $untilMs],
        );
    }

    /** MySQL and MariaDB say "on duplicate key update" where PostgreSQL and SQLite say "on conflict". */
    private static function mysql(Connection $db): bool
    {
        return in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Lines, warnings and errors per bucket over the last `$minutes`, empty
     * buckets included so a spike stands out.
     *
     * @param  list<int>  $machineIds
     * @return array{bucketMinutes: int, buckets: list<array{at: int, lines: int, warn: int, error: int}>}
     */
    public static function histogram(array $machineIds, int $minutes, ?string $service = null, ?string $level = null, ?string $connection = null): array
    {
        $bucketMinutes = self::BUCKETS[array_key_last(self::BUCKETS)];
        foreach (self::BUCKETS as $size) {
            if ($minutes / $size <= self::MAX_BARS) {
                $bucketMinutes = $size;
                break;
            }
        }
        $size = $bucketMinutes * self::MINUTE;

        $now = CarbonImmutable::now()->getTimestampMs();
        // Whole buckets, ending with the one now falls in.
        $last = $now - ($now % $size);
        $first = $last - (int) ceil($minutes / $bucketMinutes - 1) * $size;

        $counts = [];
        if ($machineIds !== []) {
            // A plain integer, worked out here: safe to put in the SQL.
            $rows = DB::connection($connection ?? LogStore::ours())->table('log_counts')
                ->whereIn('machine_id', $machineIds)
                ->where('minute', '>=', $first)
                ->when($service !== null, fn ($q) => $q->where('service', $service))
                ->when($level !== null, fn ($q) => $q->whereIn('level', $level === 'error' ? ['error'] : ['error', 'warn']))
                ->selectRaw("minute - (minute % {$size}) as bucket, level, sum(n) as n")
                ->groupByRaw("minute - (minute % {$size}), level")
                ->get();

            foreach ($rows as $row) {
                $bucket = (int) $row->bucket;
                $counts[$bucket] ??= ['lines' => 0, 'warn' => 0, 'error' => 0];
                $counts[$bucket]['lines'] += (int) $row->n;
                if ($row->level === 'error' || $row->level === 'warn') {
                    $counts[$bucket][$row->level] += (int) $row->n;
                }
            }
        }

        $buckets = [];
        for ($at = $first; $at <= $last; $at += $size) {
            $buckets[] = ['at' => $at, ...($counts[$at] ?? ['lines' => 0, 'warn' => 0, 'error' => 0])];
        }

        return ['bucketMinutes' => $bucketMinutes, 'buckets' => $buckets];
    }

    /**
     * Drop the machines' counts older than a time, as their lines are pruned.
     *
     * @param  list<int>  $machineIds
     */
    public static function prune(array $machineIds, int $beforeMs, ?string $connection = null): int
    {
        return DB::connection($connection ?? LogStore::ours())->table('log_counts')->whereIn('machine_id', $machineIds)->where('minute', '<', $beforeMs - ($beforeMs % self::MINUTE))->delete();
    }
}
