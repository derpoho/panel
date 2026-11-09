<?php

namespace App\Support\Fixes;

use App\Models\Machine;

/**
 * Everything the agent may propose, and nothing else. Each step is checked
 * against the machine's inventory before it's shown, and again before it
 * runs: a step that names a service that isn't there, isn't managed by
 * ServerOS, or (for commands) isn't a container is dropped. There's no
 * shell on the host and no reboot; the agent can't run anything itself.
 */
final class FixActions
{
    /** type => what it does, in words for the agent and the page. */
    public const TYPES = [
        'restart' => 'Restart a managed service',
        'start' => 'Start a stopped managed service',
        'stop' => 'Stop a managed service',
        'reload' => 'Reload a managed service’s configuration',
        'repair' => 'Recreate a managed service from how ServerOS deployed it',
        'command' => 'Run a shell command inside a managed container',
    ];

    public const MAX_STEPS = 4;

    public const MAX_COMMAND = 500;

    /**
     * The steps that are allowed on this machine, cleaned; the rest dropped.
     *
     * @return list<array{type: string, service: string, service_name: string, command?: string, why: string}>
     */
    public static function clean(mixed $steps, Machine $machine): array
    {
        $services = collect(is_array($machine->inventory['services'] ?? null) ? $machine->inventory['services'] : [])
            ->filter(fn ($s) => is_array($s) && is_string($s['key'] ?? null))
            ->keyBy('key');

        $out = [];
        foreach (is_array($steps) ? $steps : [] as $step) {
            if (! is_array($step) || count($out) >= self::MAX_STEPS) {
                continue;
            }
            $type = (string) ($step['type'] ?? '');
            $key = (string) ($step['service'] ?? '');
            $service = $services->get($key);
            if (! isset(self::TYPES[$type]) || $service === null || ($service['details']['managed'] ?? null) !== 'true') {
                continue;
            }
            $clean = [
                'type' => $type,
                'service' => $key,
                'service_name' => (string) ($service['name'] ?? $key),
                'why' => mb_substr(trim((string) ($step['why'] ?? '')), 0, 400),
            ];
            if ($type === 'command') {
                $command = trim((string) ($step['command'] ?? ''));
                if (! str_starts_with($key, 'docker:') || $command === '' || mb_strlen($command) > self::MAX_COMMAND) {
                    continue;
                }
                $clean['command'] = $command;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * The daemon job for a step: [kind, job].
     *
     * @param  array<string, mixed>  $step
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function job(array $step): array
    {
        return match ($step['type']) {
            'command' => ['service_exec', ['type' => 'service_exec', 'service' => $step['service'], 'command' => $step['command'], 'timeout_secs' => 300]],
            'repair' => ['service_repair', ['type' => 'service_repair', 'service' => $step['service']]],
            default => ['service_action', ['type' => 'service_action', 'service' => $step['service'], 'action' => $step['type']]],
        };
    }
}
