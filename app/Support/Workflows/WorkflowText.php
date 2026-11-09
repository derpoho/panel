<?php

namespace App\Support\Workflows;

use App\Models\WorkflowRun;

/**
 * Fills {{placeholders}} in what a step says or sends: {{workflow}},
 * {{trigger}}, {{machine}}, {{service}}, {{run_url}}, {{data.<field>}}
 * for anything the trigger carried (a webhook's JSON, say), and
 * {{agent.summary}} and {{agent.pr}} from the last agent step that finished.
 */
final class WorkflowText
{
    public static function render(string $text, WorkflowRun $run, bool $encode = false): string
    {
        $data = $run->trigger['data'] ?? [];
        $workflow = $run->workflow;
        $values = [
            'workflow' => $workflow->name,
            'trigger' => $run->trigger['summary'] ?? '',
            'machine' => (string) ($data['machine'] ?? ''),
            'service' => (string) ($data['service'] ?? ''),
            'run_url' => 'https://'.$workflow->workspace->host()."/workflows/{$workflow->id}?run={$run->id}",
        ];
        $agent = self::lastAgent($run);
        $values['agent.summary'] = $agent['summary'] ?? '';
        $values['agent.pr'] = $agent['pr'] ?? '';

        return preg_replace_callback('/\{\{\s*([a-z_]+(?:\.[A-Za-z0-9_.-]+)?)\s*\}\}/', function (array $m) use ($values, $data, $encode) {
            $key = $m[1];
            $value = str_starts_with($key, 'data.') ? data_get($data, substr($key, 5)) : ($values[$key] ?? null);
            if ($value === null) {
                return '';
            }
            $value = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');

            return $encode ? rawurlencode($value) : $value;
        }, $text) ?? $text;
    }

    /**
     * The summary and pull request of the agent step that finished last.
     *
     * @return array{summary?: string, pr?: string|null}
     */
    private static function lastAgent(WorkflowRun $run): array
    {
        $agents = collect($run->graph['nodes'] ?? [])->where('kind', 'agent')->pluck('id')->all();

        return collect($run->steps ?? [])
            ->only($agents)
            ->filter(fn (array $step) => isset($step['finishedAt']))
            ->sortBy('finishedAt')
            ->last() ?? [];
    }

    public static function kindLabel(string $kind): string
    {
        return [
            'trigger' => 'Trigger',
            'redeploy' => 'Redeploy',
            'release' => 'Release app',
            'restart' => 'Restart service',
            'start' => 'Start service',
            'stop' => 'Stop service',
            'command' => 'Run command',
            'agent' => 'Run agent',
            'backup' => 'Back up',
            'copy_data' => 'Copy data',
            'promote' => 'Promote',
            'check_monitor' => 'Check monitor',
            'http' => 'HTTP request',
            'alert' => 'Send alert',
            'wait' => 'Wait',
            'branch' => 'If',
        ][$kind] ?? $kind;
    }
}
