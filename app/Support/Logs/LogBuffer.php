<?php

namespace App\Support\Logs;

use Illuminate\Support\Facades\Cache;

/**
 * Log lines waiting for a workspace's own store while it's unreachable,
 * kept in the cache until logs:flush-buffers delivers them. Lines sit in
 * segments of up to SEGMENT rows, so a push or a take touches a few keys,
 * never one huge value. Past CAP lines the oldest go, and are counted.
 *
 * @phpstan-import-type LogRow from LogIngest
 */
final class LogBuffer
{
    /** Most lines held per workspace. */
    public const CAP = 100_000;

    /** Rows per cache key. */
    private const SEGMENT = 1000;

    /** Kept a week: a store down longer than that has bigger problems. */
    private const TTL_DAYS = 7;

    /**
     * Add rows at the end, dropping the oldest past the cap.
     *
     * @param  list<LogRow>  $rows
     */
    public static function push(int $workspaceId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        self::locked($workspaceId, function () use ($workspaceId, $rows): void {
            $meta = self::meta($workspaceId);

            // Fill the last segment before starting a new one.
            if ($meta['counts'] !== [] && end($meta['counts']) < self::SEGMENT) {
                $index = $meta['tail'] - 1;
                $segment = self::segment($workspaceId, $index);
                $room = self::SEGMENT - count($segment);
                self::putSegment($workspaceId, $index, [...$segment, ...array_slice($rows, 0, $room)]);
                $meta['counts'][$index] = min(self::SEGMENT, count($segment) + count($rows));
                $rows = array_slice($rows, $room);
            }
            foreach (array_chunk($rows, self::SEGMENT) as $chunk) {
                self::putSegment($workspaceId, $meta['tail'], $chunk);
                $meta['counts'][$meta['tail']] = count($chunk);
                $meta['tail']++;
            }

            self::save($workspaceId, self::trim($workspaceId, $meta));
        });
    }

    /**
     * Put rows that couldn't be delivered back at the front, oldest first.
     *
     * @param  list<LogRow>  $rows
     */
    public static function restore(int $workspaceId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        self::locked($workspaceId, function () use ($workspaceId, $rows): void {
            $meta = self::meta($workspaceId);
            $counts = [];
            foreach (array_reverse(array_chunk($rows, self::SEGMENT)) as $chunk) {
                $meta['head']--;
                self::putSegment($workspaceId, $meta['head'], $chunk);
                $counts[$meta['head']] = count($chunk);
            }
            $meta['counts'] = array_reverse($counts, true) + $meta['counts'];

            self::save($workspaceId, self::trim($workspaceId, $meta));
        });
    }

    /**
     * Remove and return up to `$limit` of the oldest rows.
     *
     * @return list<LogRow>
     */
    public static function take(int $workspaceId, int $limit): array
    {
        return self::locked($workspaceId, function () use ($workspaceId, $limit): array {
            $meta = self::meta($workspaceId);
            $taken = [];

            while ($meta['counts'] !== [] && count($taken) < $limit) {
                $index = (int) array_key_first($meta['counts']);
                $segment = self::segment($workspaceId, $index);
                $want = $limit - count($taken);
                $taken = [...$taken, ...array_slice($segment, 0, $want)];

                if (count($segment) > $want) {
                    $rest = array_slice($segment, $want);
                    self::putSegment($workspaceId, $index, $rest);
                    $meta['counts'][$index] = count($rest);
                } else {
                    Cache::forget(self::key($workspaceId, "seg:{$index}"));
                    unset($meta['counts'][$index]);
                    $meta['head'] = $index + 1;
                }
            }

            self::save($workspaceId, $meta);

            return $taken;
        });
    }

    public static function size(int $workspaceId): int
    {
        return array_sum(self::meta($workspaceId)['counts']);
    }

    /**
     * Throw away one machine's waiting lines, so lines someone cleared
     * don't arrive in the store later. Other machines' lines keep their
     * place; a segment left empty is skipped by the next take. Returns how
     * many went.
     */
    public static function forgetMachine(int $workspaceId, int $machineId): int
    {
        return self::forget($workspaceId, fn (array $row) => $row['machine_id'] === $machineId);
    }

    /**
     * Throw away a machine's waiting lines from these services (by their
     * exact name), like forgetMachine. Returns how many went.
     *
     * @param  list<string>  $services
     */
    public static function forgetServices(int $workspaceId, int $machineId, array $services): int
    {
        return self::forget($workspaceId, fn (array $row) => $row['machine_id'] === $machineId && in_array($row['service'], $services, true));
    }

    /** @param  callable(LogRow): bool  $goes */
    private static function forget(int $workspaceId, callable $goes): int
    {
        if (self::size($workspaceId) === 0) {
            return 0;
        }

        return self::locked($workspaceId, function () use ($workspaceId, $goes): int {
            $meta = self::meta($workspaceId);
            $removed = 0;
            foreach (array_keys($meta['counts']) as $index) {
                $segment = self::segment($workspaceId, $index);
                $kept = array_values(array_filter($segment, fn (array $row) => ! $goes($row)));
                if (count($kept) === count($segment)) {
                    continue;
                }
                $removed += count($segment) - count($kept);
                self::putSegment($workspaceId, $index, $kept);
                $meta['counts'][$index] = count($kept);
            }
            self::save($workspaceId, $meta);

            return $removed;
        });
    }

    /** Lines dropped past the cap, kept after the buffer empties so it can be shown. */
    public static function dropped(int $workspaceId): int
    {
        return self::meta($workspaceId)['dropped'];
    }

    /**
     * Drop whole and then part segments from the front until within the cap.
     *
     * @param  array{head: int, tail: int, dropped: int, counts: array<int, int>}  $meta
     * @return array{head: int, tail: int, dropped: int, counts: array<int, int>}
     */
    private static function trim(int $workspaceId, array $meta): array
    {
        $over = array_sum($meta['counts']) - self::CAP;
        while ($over > 0 && $meta['counts'] !== []) {
            $index = (int) array_key_first($meta['counts']);
            $count = $meta['counts'][$index];

            if ($count <= $over) {
                Cache::forget(self::key($workspaceId, "seg:{$index}"));
                unset($meta['counts'][$index]);
                $meta['head'] = $index + 1;
                $meta['dropped'] += $count;
                $over -= $count;

                continue;
            }

            self::putSegment($workspaceId, $index, array_slice(self::segment($workspaceId, $index), $over));
            $meta['counts'][$index] = $count - $over;
            $meta['dropped'] += $over;
            $over = 0;
        }

        return $meta;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function locked(int $workspaceId, callable $callback): mixed
    {
        return Cache::lock(self::key($workspaceId, 'lock'), 30)->block(10, $callback);
    }

    /** @return array{head: int, tail: int, dropped: int, counts: array<int, int>} */
    private static function meta(int $workspaceId): array
    {
        $meta = Cache::get(self::key($workspaceId, 'meta'));
        if (! is_array($meta)) {
            return ['head' => 0, 'tail' => 0, 'dropped' => 0, 'counts' => []];
        }

        return [
            'head' => (int) ($meta['head'] ?? 0),
            'tail' => (int) ($meta['tail'] ?? 0),
            'dropped' => (int) ($meta['dropped'] ?? 0),
            'counts' => array_map(intval(...), is_array($meta['counts'] ?? null) ? $meta['counts'] : []),
        ];
    }

    /** @param  array{head: int, tail: int, dropped: int, counts: array<int, int>}  $meta */
    private static function save(int $workspaceId, array $meta): void
    {
        Cache::put(self::key($workspaceId, 'meta'), $meta, now()->addDays(self::TTL_DAYS));
    }

    /** @return list<LogRow> */
    private static function segment(int $workspaceId, int $index): array
    {
        $stored = Cache::get(self::key($workspaceId, "seg:{$index}"));
        $rows = [];
        // Only rows of the shape LogIngest wrote: anything else in the cache
        // (an older format, a stray value) would fail the insert forever.
        foreach (is_array($stored) ? $stored : [] as $row) {
            if (is_array($row) && is_int($row['machine_id'] ?? null) && is_int($row['logged_at'] ?? null)
                && is_string($row['service_key'] ?? null) && is_string($row['service'] ?? null) && is_string($row['level'] ?? null)
                && is_string($row['stream'] ?? null) && is_string($row['message'] ?? null)) {
                $rows[] = ['machine_id' => $row['machine_id'], 'service_key' => $row['service_key'], 'service' => $row['service'],
                    'logged_at' => $row['logged_at'], 'level' => $row['level'], 'stream' => $row['stream'], 'message' => $row['message']];
            }
        }

        return $rows;
    }

    /** @param  list<LogRow>  $rows */
    private static function putSegment(int $workspaceId, int $index, array $rows): void
    {
        Cache::put(self::key($workspaceId, "seg:{$index}"), $rows, now()->addDays(self::TTL_DAYS));
    }

    private static function key(int $workspaceId, string $what): string
    {
        return "log-buffer:{$workspaceId}:{$what}";
    }
}
