<?php

namespace App\Support\Logs;

use App\Models\LogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deleting stored log lines on request: what the Logs page's filters
 * match, or lines picked one by one. Never past `upTo`, the newest line
 * when the person saw the count, so lines that arrive while they confirm
 * stay. Runs in chunks along the (machine_id, id) index so a big delete
 * never holds the table, and takes each chunk off the chart's per-minute
 * counts as it goes. Everything runs on the connection the workspace's
 * lines live on (LogStore), ours unless said.
 *
 * @phpstan-type Scope array{service: string|null, level: string|null, q: string|null, since: int|null, ids: list<int>|null}
 */
final class LogDeletion
{
    /** Lines deleted in one statement. */
    public const CHUNK = 2000;

    /** Deletes bigger than this run in the background. */
    public const SYNC_LIMIT = 20_000;

    /**
     * One machine's lines a scope matches: picked ids, or the filters.
     *
     * @param  Scope  $scope
     * @return Builder<LogEntry>
     */
    public static function query(int $machineId, array $scope, int $upTo, ?string $connection = null): Builder
    {
        $query = LogEntry::on($connection ?? LogStore::ours())->where('machine_id', $machineId)->where('id', '<=', $upTo);
        if ($scope['ids'] !== null) {
            return $query->whereIn('id', $scope['ids']);
        }

        return $query
            ->when($scope['service'], fn (Builder $q, string $service) => $q->where('service', $service))
            ->when($scope['level'], fn (Builder $q, string $level) => $q->whereIn('level', $level === 'error' ? ['error'] : ['error', 'warn']))
            ->when($scope['since'], fn (Builder $q, int $since) => $q->where('logged_at', '>=', $since))
            ->when($scope['q'], fn (Builder $q, string $text) => $q->whereLike('message', '%'.addcslashes($text, '%_\\').'%'));
    }

    /**
     * @param  list<int>  $machineIds
     * @param  Scope  $scope
     */
    public static function count(array $machineIds, array $scope, int $upTo, ?string $connection = null): int
    {
        return array_sum(array_map(fn (int $id) => self::query($id, $scope, $upTo, $connection)->count(), $machineIds));
    }

    /**
     * @param  list<int>  $machineIds
     * @param  Scope  $scope
     */
    public static function run(array $machineIds, array $scope, int $upTo, ?string $connection = null): int
    {
        $connection ??= LogStore::ours();
        $deleted = 0;

        foreach ($machineIds as $machineId) {
            $after = 0;
            do {
                $rows = self::query($machineId, $scope, $upTo, $connection)
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get(['id', 'machine_id', 'service', 'logged_at', 'level']);
                if ($rows->isEmpty()) {
                    break;
                }
                $after = $rows->max('id');

                DB::connection($connection)->transaction(function () use ($rows, $connection, &$deleted): void {
                    $deleted += LogEntry::on($connection)->whereIn('id', $rows->modelKeys())->delete();
                    LogCounts::subtract(array_values($rows->map(fn (LogEntry $row) => [
                        'machine_id' => $row->machine_id,
                        'service' => $row->service,
                        'logged_at' => $row->logged_at,
                        'level' => $row->level,
                    ])->all()), $connection);
                });
            } while ($rows->count() === self::CHUNK);
        }

        return $deleted;
    }
}
