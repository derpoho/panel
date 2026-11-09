<?php

namespace App\Support\Logs;

use App\Models\LogEntry;
use App\Models\Machine;
use App\Support\Deploys\ServiceLabels;
use App\Support\Machines\MachinePolicies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores a batch of log lines a daemon shipped. Lines are checked one by
 * one: a malformed line is skipped, never the batch. Lines from services
 * whose logs are muted are dropped, and so are lines past the workspace's
 * daily allowance (LogQuota), and every line from a machine whose policy
 * has logs off.
 *
 * Lines go where LogStore says. A workspace's own store that can't be
 * written to gets its lines buffered (LogBuffer) for logs:flush-buffers,
 * never written to ours, and the daemon is still told they arrived.
 *
 * @phpstan-type LogRow array{machine_id: int, service_key: string, service: string, logged_at: int, level: string, stream: string, message: string}
 */
final class LogIngest
{
    /** Lines kept from one batch; the daemon sends at most 500. */
    public const MAX_LINES = 1000;

    public const MAX_MESSAGE = 8192;

    /** Rows per insert, and per transaction on an own store. */
    public const CHUNK = 250;

    /** After an own store fails, how long batches skip straight to the buffer. */
    private const DOWN_SECONDS = 60;

    /** At most one "it answered" write to the store's row per this long. */
    private const OK_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $payload
     * @return int lines stored
     */
    public static function store(Machine $machine, array $payload): int
    {
        $lines = $payload['lines'] ?? null;
        if (! is_array($lines)) {
            return 0;
        }
        // Logs off in the machine's policy: nothing is kept, even from a
        // daemon too old to stop sending.
        if (! MachinePolicies::for($machine)->logs) {
            return 0;
        }

        $now = (int) floor(microtime(true) * 1000);
        $services = self::inventoryServices($machine);
        $muted = $machine->log_muted_services ?? [];
        $rows = [];

        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            if (! is_array($line) || ! is_string($line['service'] ?? null) || ! is_string($line['line'] ?? null)) {
                continue;
            }

            $message = mb_strcut($line['line'], 0, self::MAX_MESSAGE, 'UTF-8');
            if (trim($message) === '') {
                continue;
            }

            $priority = is_int($line['priority'] ?? null) ? $line['priority'] : null;
            $stream = in_array($line['stream'] ?? null, ['stdout', 'stderr', 'journal'], true) ? $line['stream'] : 'stdout';
            // A clock far off on the machine shouldn't bury lines in the past
            // or pin them to the top: keep the daemon's time within a day.
            $at = is_int($line['ts'] ?? null) && abs($line['ts'] - $now) < 86_400_000 ? $line['ts'] : $now;

            $key = substr($line['service'], 0, 300);
            $name = is_string($line['name'] ?? null) && $line['name'] !== '' ? $line['name'] : ($services[$key]['name'] ?? $key);
            if ($muted !== [] && in_array(ServiceLabels::identity($services[$key] ?? ['key' => $key, 'name' => $name]), $muted, true)) {
                continue;
            }

            $rows[] = [
                'machine_id' => $machine->id,
                'service_key' => $key,
                'service' => mb_substr($name, 0, 200),
                'logged_at' => $at,
                'level' => LogLevel::detect($message, $priority),
                'stream' => $stream,
                'message' => $message,
            ];
        }

        $workspace = $machine->workspace;
        if ($workspace !== null) {
            $rows = array_slice($rows, 0, LogQuota::take($workspace, count($rows)));
        }

        $connection = $workspace === null ? LogStore::ours() : LogStore::connection($workspace);
        if ($workspace === null || $connection === LogStore::ours()) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                LogEntry::on($connection)->insert($chunk);
            }
            LogCounts::add($rows, $connection);

            return count($rows);
        }

        self::storeOwn($workspace->id, $connection, $rows);

        return count($rows);
    }

    /**
     * Write to a workspace's own store, or buffer what can't be written.
     * A store that just failed isn't tried again for a minute: each try at
     * a dead host can wait seconds, and the daemon is waiting too.
     *
     * @param  list<LogRow>  $rows
     */
    private static function storeOwn(int $workspaceId, string $connection, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (Cache::has(self::downKey($workspaceId))) {
            LogBuffer::push($workspaceId, $rows);

            return;
        }

        $chunks = array_chunk($rows, self::CHUNK);
        foreach ($chunks as $i => $chunk) {
            try {
                self::write($connection, $chunk);
            } catch (Throwable $e) {
                // Anything going wrong in their database (down, full, wrong
                // password, tables dropped) keeps the lines for later.
                LogBuffer::push($workspaceId, array_merge(...array_slice($chunks, $i)));
                self::failed($workspaceId, $e);

                return;
            }
        }
        self::answered($workspaceId);
    }

    /**
     * Write rows and their counts to an own store, together or not at all,
     * so a retry never counts a line twice.
     *
     * @param  list<LogRow>  $rows
     */
    public static function write(string $connection, array $rows): void
    {
        DB::connection($connection)->transaction(function () use ($connection, $rows): void {
            LogEntry::on($connection)->insert($rows);
            LogCounts::add($rows, $connection);
        });
    }

    /** The store took lines: note it, at most once a minute unless it was down. */
    public static function answered(int $workspaceId): void
    {
        Cache::forget(self::downKey($workspaceId));
        if (Cache::add(self::okKey($workspaceId), true, self::OK_SECONDS)) {
            LogStore::markOk($workspaceId);
        }
    }

    /** The store refused lines: note why, and buffer for a while without trying. */
    public static function failed(int $workspaceId, Throwable $e): void
    {
        LogStore::markUnreachable($workspaceId, $e);
        Cache::forget(self::okKey($workspaceId));
        Cache::put(self::downKey($workspaceId), true, self::DOWN_SECONDS);
    }

    private static function downKey(int $workspaceId): string
    {
        return "log-store:{$workspaceId}:down";
    }

    private static function okKey(int $workspaceId): string
    {
        return "log-store:{$workspaceId}:ok";
    }

    /**
     * The machine's last scan by service key: names for lines from a daemon
     * too old to send them, and what muting matches against.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function inventoryServices(Machine $machine): array
    {
        $services = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && is_string($service['key'] ?? null) && is_string($service['name'] ?? null)) {
                $services[$service['key']] = $service;
            }
        }

        return $services;
    }
}
