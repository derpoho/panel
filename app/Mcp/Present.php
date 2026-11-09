<?php

namespace App\Mcp;

use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * How machines, services and server jobs look to an agent: compact, with
 * the ids and keys it needs to act on them, and plain words for states.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function machine(Machine $machine, McpContext $context): array
    {
        $facts = $machine->facts ?? [];

        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'environment' => $machine->environment->value,
            'status' => self::connection($machine),
            'ip' => $machine->ip_address,
            'provider' => $machine->provider,
            'os' => trim(($facts['os_name'] ?? $facts['os'] ?? '').' '.($facts['os_version'] ?? '')) ?: null,
            'cpuPercent' => $machine->cpu_percent,
            'memoryUsedMb' => $machine->memory_used_mb,
            'diskPercent' => $machine->disk_percent,
            'services' => $context->services($machine)->count(),
            'groupId' => $machine->machine_group_id,
            'maintenance' => $machine->inMaintenance() ? [
                'until' => $machine->maintenance_until->toIso8601String(),
                'reason' => $machine->maintenance_reason,
            ] : null,
            'lastSeen' => $machine->last_seen_at?->toIso8601String(),
            'daemonVersion' => $machine->daemon_version,
            'icon' => $machine->iconUrl(),
            'url' => $context->url("/machines/{$machine->id}"),
        ];
    }

    /**
     * @param  array<string, mixed>  $service  from McpContext::describeService()
     * @return array<string, mixed>
     */
    public static function service(Machine $machine, array $service, McpContext $context): array
    {
        $key = (string) $service['key'];

        return [
            'key' => $key,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'name' => $service['display'] ?? $service['name'] ?? $key,
            'foundAs' => $service['name'] ?? null,
            'label' => $service['label'] ?? null,
            'app' => $service['app'] ?? null,
            'kind' => $service['kind'] ?? null,
            'manager' => $service['manager'] ?? null,
            'status' => $service['status'] ?? 'unknown',
            'managed' => (bool) ($service['managed'] ?? false),
            'container' => (bool) ($service['container'] ?? false),
            'image' => $service['details']['image'] ?? null,
            'ports' => array_values($service['ports'] ?? []),
            'icon' => $service['icon'] ?? null,
            'stats' => $machine->service_stats[$key] ?? null,
            'url' => $context->url('/machines/'.$machine->id.'/services/'.rawurlencode(($service['app'] ?? null) ? 'app:'.$service['app'] : $key)),
        ];
    }

    /** @return array<string, mixed> */
    public static function job(DaemonJob $job, int $logLines = 40): array
    {
        $log = array_values($job->log ?? []);

        return [
            'id' => $job->id,
            'kind' => $job->kind,
            'state' => $job->state,
            'finished' => in_array($job->state, McpContext::FINISHED, true),
            'machineId' => $job->machine_id,
            'service' => $job->service,
            'phase' => $job->phase,
            'progress' => $job->progress,
            'log' => array_slice($log, -$logLines),
            'logLines' => count($log),
            'result' => $job->result,
            'error' => $job->error,
            'createdAt' => $job->created_at?->toIso8601String(),
            'finishedAt' => $job->finished_at?->toIso8601String(),
        ];
    }

    public static function connection(Machine $machine): string
    {
        return match (true) {
            $machine->uid === null => 'demo',
            $machine->last_seen_at === null => 'connecting',
            $machine->disconnected_at !== null || $machine->last_seen_at->lt(now()->subSeconds(60)) => 'offline',
            default => 'online',
        };
    }
}
