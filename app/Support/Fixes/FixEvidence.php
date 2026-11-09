<?php

namespace App\Support\Fixes;

use App\Models\DaemonJob;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Support\Logs\LogStore;
use Throwable;

/**
 * What the agent reads before it says anything: the machine's state, the
 * service's status, its recent log lines, the machine's recent errors and
 * the jobs that failed there. All of it is already in the panel; nothing is
 * run on the machine to gather it. Kept small so it fits in one request.
 */
final class FixEvidence
{
    public const SERVICE_LINES = 80;

    public const MACHINE_ERRORS = 25;

    public const FAILED_JOBS = 5;

    /**
     * @return array{machine: array<string, mixed>, service: array<string, mixed>|null, services: list<array<string, mixed>>, logs: list<string>, errors: list<string>, jobs: list<array<string, mixed>>}
     */
    public static function gather(Machine $machine, ?string $serviceKey): array
    {
        $services = collect(is_array($machine->inventory['services'] ?? null) ? $machine->inventory['services'] : [])
            ->filter(fn ($s) => is_array($s) && is_string($s['key'] ?? null));
        $service = $serviceKey === null ? null : $services->firstWhere('key', $serviceKey);

        return [
            'machine' => array_filter([
                'name' => $machine->name,
                'online' => $machine->isOnline(),
                'last_seen' => $machine->last_seen_at?->toIso8601String(),
                'cpu_percent' => $machine->cpu_percent,
                'memory_used_mb' => $machine->memory_used_mb,
                'disk_percent' => $machine->disk_percent,
                'disks' => $machine->disks,
                'os' => $machine->facts['os'] ?? null,
            ], fn ($v) => $v !== null),
            'service' => $service === null ? null : self::service($service),
            'services' => array_values($services->take(40)->map(fn (array $s) => self::service($s, brief: true))->all()),
            'logs' => $serviceKey === null ? [] : self::lines($machine, $serviceKey, self::SERVICE_LINES),
            'errors' => self::lines($machine, null, self::MACHINE_ERRORS, errorsOnly: true),
            'jobs' => array_values(DaemonJob::query()
                ->where('machine_id', $machine->id)
                ->whereIn('state', ['failed', 'timed_out', 'refused'])
                ->where('created_at', '>=', now()->subDays(2))
                ->latest()
                ->limit(self::FAILED_JOBS)
                ->get()
                ->map(fn (DaemonJob $j) => [
                    'kind' => $j->kind,
                    'service' => $j->service,
                    'state' => $j->state,
                    'when' => $j->created_at?->toIso8601String(),
                    'error' => is_array($j->error) ? mb_substr((string) ($j->error['message'] ?? ''), 0, 300) : null,
                    'log' => array_map(fn ($l) => mb_substr((string) $l, 0, 300), array_slice(array_values(array_filter($j->log ?? [], 'is_string')), -12)),
                ])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private static function service(array $s, bool $brief = false): array
    {
        $out = [
            'key' => $s['key'],
            'name' => $s['name'] ?? $s['key'],
            'status' => $s['status'] ?? null,
            'managed' => ($s['details']['managed'] ?? null) === 'true',
            'container' => str_starts_with((string) $s['key'], 'docker:'),
        ];
        if (! $brief) {
            $out['kind'] = $s['kind'] ?? null;
            $out['ports'] = $s['ports'] ?? [];
            $out['details'] = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 200) : null,
                array_intersect_key((array) ($s['details'] ?? []), array_flip(['image', 'restart_count', 'exit_code', 'started_at', 'health', 'state', 'memory_mb', 'cpu_percent', 'unit_state', 'sub_state'])));
        }

        return $out;
    }

    /**
     * The newest log lines, oldest first: one service's, or the last two
     * days' errors and warnings from anything on the machine.
     *
     * @return list<string>
     */
    private static function lines(Machine $machine, ?string $serviceKey, int $limit, bool $errorsOnly = false): array
    {
        try {
            $query = LogStore::entries($machine->workspace_id)->where('machine_id', $machine->id);
            // A service's newest lines, however old; the rest of the machine's
            // errors only from the last two days.
            if ($serviceKey !== null) {
                $query->where('service_key', $serviceKey);
            } else {
                $query->where('logged_at', '>=', (int) now()->subDays(2)->getTimestampMs());
            }
            if ($errorsOnly) {
                $query->whereIn('level', ['error', 'warn']);
            }

            return array_values($query->orderByDesc('logged_at')->limit($limit)->get()
                ->reverse()
                ->map(fn (LogEntry $e) => '['.gmdate('M j H:i:s', intdiv($e->logged_at, 1000)).' UTC] '.($serviceKey === null ? "{$e->service}: " : '').strtoupper($e->level).' '.mb_substr($e->message, 0, 400))
                ->all());
        } catch (Throwable $e) {
            // A workspace's own log database being down shouldn't stop the
            // agent; it just has less to go on.
            report($e);

            return [];
        }
    }
}
