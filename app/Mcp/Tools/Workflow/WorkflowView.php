<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How workflows and their runs look to an agent: the list row the
 * Workflows page shows, the whole workflow as the editor has it (graph,
 * steps in words, problems), and a run with each step's state.
 */
final class WorkflowView
{
    /**
     * A row of the Workflows page.
     *
     * @param  Collection<int, WorkflowRun>  $recent  its runs of the last 7 days
     * @return array<string, mixed>
     */
    public static function row(Workflow $workflow, Collection $recent, WorkflowWords $words, McpContext $context): array
    {
        $last = $recent->sortByDesc('id')->first();

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'enabled' => $workflow->enabled,
            'trigger' => $workflow->trigger,
            'triggerWords' => $words->trigger($workflow->triggerRules()),
            'steps' => max(0, count($workflow->graph['nodes'] ?? []) - 1),
            'lastRun' => $last ? ['id' => $last->id, 'state' => $last->state, 'at' => $last->started_at->toIso8601String()] : null,
            'lastRunAt' => $workflow->last_run_at?->toIso8601String(),
            'runs7d' => $recent->count(),
            'failed7d' => $recent->where('state', 'failed')->count(),
            'nextRunAt' => $workflow->enabled ? $workflow->next_run_at?->toIso8601String() : null,
            'url' => $context->url("/workflows/{$workflow->id}"),
        ];
    }

    /**
     * The whole workflow, as the editor opens it.
     *
     * @return array<string, mixed>
     */
    public static function full(Workflow $workflow, McpContext $context, ?WorkflowWords $words = null, int $runs = 10): array
    {
        $words ??= WorkflowWords::for($context->workspace);
        $graph = WorkflowSteps::clean($workflow->graph);
        $found = WorkflowSteps::problems($graph, $context->workspace);
        $problems = Workflows::problems($found, $graph);
        $plan = $context->workspace->hasAutomations();
        $rules = $workflow->triggerRules();

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'enabled' => $workflow->enabled,
            'trigger' => [
                'event' => $workflow->trigger,
                'label' => WorkflowReference::label($workflow->trigger),
                'words' => $words->trigger($rules),
            ],
            'webhook' => $workflow->trigger === 'webhook' ? [
                'hasUrl' => $workflow->webhook_token !== null,
                'note' => $workflow->webhook_token !== null
                    ? 'The webhook URL is a secret, so it isn’t shown here: the person can copy it from the trigger step on the workflow’s dashboard page (url), with a GitHub Actions snippet. POST JSON to it to start a run; its fields are {{data.<field>}}. It only starts runs while the workflow is on.'
                    : 'Saving the workflow (update_workflow) makes its webhook URL, shown on its dashboard page.',
            ] : null,
            'runAs' => $workflow->runAs?->name,
            'nextRunAt' => $workflow->enabled ? $workflow->next_run_at?->toIso8601String() : null,
            'lastRunAt' => $workflow->last_run_at?->toIso8601String(),
            'updatedAt' => $workflow->updated_at?->toIso8601String(),
            'graph' => self::graph($graph),
            'steps' => self::steps($graph, $words, $found),
            'problems' => $problems,
            'hints' => self::hints($graph, $words),
            'canTurnOn' => $plan && $problems === [],
            'planAllows' => $plan,
            'recentRuns' => $runs > 0 ? $workflow->runs()->with('user:id,name')->latest('id')->limit($runs)->get()
                ->map(fn (WorkflowRun $r) => self::run($r, $context, false))->values()->all() : [],
            'url' => $context->url("/workflows/{$workflow->id}"),
        ];
    }

    /**
     * The graph as stored, in the shape update_workflow takes back.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function graph(array $graph): array
    {
        return [
            'nodes' => array_map(fn (array $n) => [
                'id' => $n['id'],
                'kind' => $n['kind'],
                'title' => $n['title'],
                'description' => $n['description'],
                'rules' => (object) (array) $n['rules'],
                'x' => $n['x'],
                'y' => $n['y'],
            ], $graph['nodes']),
            'edges' => array_map(fn (array $e) => [
                'id' => $e['id'],
                'source' => $e['source'],
                'port' => $e['port'],
                'target' => $e['target'],
            ], $graph['edges']),
        ];
    }

    /**
     * The steps in the order a run reaches them, each in words with where
     * it goes next; steps nothing leads to come last, marked.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  array<string, string>  $problems
     * @return list<array<string, mixed>>
     */
    public static function steps(array $graph, WorkflowWords $words, array $problems = []): array
    {
        $nodes = collect($graph['nodes'])->keyBy('id');
        $order = self::reached($graph);

        $steps = [];
        foreach ([...array_keys($order), ...$nodes->keys()->diff(array_keys($order))->all()] as $id) {
            $node = $nodes[$id];
            $next = [];
            foreach (WorkflowSteps::PORTS[$node['kind']] as $port) {
                $next[$port] = collect($graph['edges'])->where('source', $id)->where('port', $port)->pluck('target')->values()->all();
            }
            $steps[] = array_filter([
                'id' => $id,
                'kind' => $node['kind'],
                'label' => WorkflowText::kindLabel($node['kind']),
                'title' => $node['title'],
                'does' => $words->step($node),
                'next' => $next,
                'problem' => $problems[$id] ?? null,
                'connected' => isset($order[$id]) ? null : false,
            ], fn ($v) => $v !== null);
        }

        return $steps;
    }

    /**
     * What would make a run go wrong without stopping it being turned on:
     * a service that isn't on the machine or isn't managed, an app never
     * deployed there, a filter naming a machine that's gone, a rule the
     * step doesn't use, steps nothing leads to.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{step: string, hint: string}>
     */
    public static function hints(array $graph, WorkflowWords $words): array
    {
        $hints = [];
        $add = function (string $id, string $hint) use (&$hints) {
            $hints[] = ['step' => $id, 'hint' => $hint];
        };
        $reached = self::reached($graph);

        foreach ($graph['nodes'] as $node) {
            $id = $node['id'];
            $kind = $node['kind'];
            $rules = (array) $node['rules'];

            if (! isset($reached[$id])) {
                $add($id, 'Nothing leads to this step, so it never runs.');
            }
            $unused = array_diff(array_keys($rules), WorkflowReference::ruleNames($kind, $rules));
            if ($unused !== []) {
                $add($id, 'Not used by '.($kind === 'trigger' ? 'this trigger' : ($kind === 'branch' ? 'this check' : "a {$kind} step")).', so ignored: '.implode(', ', $unused).'.');
            }

            $machineRef = $rules['machine_id'] ?? null;
            $machine = $words->machine($machineRef);
            $service = $rules['service'] ?? null;

            if ($kind === 'trigger') {
                if (filled($machineRef) && $machine === null) {
                    $add($id, "No machine #{$machineRef} in this workspace, so this trigger never fires. Use an id from choices.machines, or leave machine_id out for any.");
                }
                if (($rules['event'] ?? null) === 'schedule' && isset($rules['at']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $rules['at']) !== 1) {
                    $add($id, '“at” should be HH:MM (UTC); 03:00 is used instead.');
                }

                continue;
            }

            $onMachine = in_array($kind, WorkflowReference::MACHINE_STEPS, true) || ($kind === 'branch' && in_array($rules['condition'] ?? null, ['service_running', 'disk_below'], true));
            if (! $onMachine || $machine === null || ! filled($service) || $service === WorkflowSteps::FROM_TRIGGER || $kind === 'backup') {
                continue;
            }
            if ($kind === 'redeploy') {
                if (! in_array($service, $machine['deploys'], true)) {
                    $add($id, "Nothing called “{$service}” has been deployed to {$machine['name']}, so there’s no deploy to repeat. Deployed there: ".($machine['deploys'] === [] ? 'nothing yet' : implode(', ', $machine['deploys'])).'.');
                }

                continue;
            }
            if ($kind === 'branch' && ($rules['condition'] ?? null) === 'disk_below') {
                continue;
            }
            $found = collect($machine['services'])->firstWhere('key', $service);
            if ($found === null) {
                $add($id, "No service with the key “{$service}” in {$machine['name']}’s latest scan. Use a key from choices.machines[].services.");
            } elseif ($kind !== 'branch' && ! $found['managed']) {
                $add($id, "{$found['name']} isn’t managed by ServerOS yet, so this step would fail; adopt it first.");
            } elseif ($kind === 'command' && ! $found['container']) {
                $add($id, "Commands run inside containers; {$found['name']} isn’t one.");
            }
        }

        return $hints;
    }

    /**
     * A run: how it was started and where it got to; with each step and
     * the log when $full.
     *
     * @return array<string, mixed>
     */
    public static function run(WorkflowRun $run, McpContext $context, bool $full = true, int $logLines = 100): array
    {
        $steps = $run->steps ?? [];
        $failed = collect($steps)->first(fn ($s) => ($s['unhandled'] ?? false) === true);
        $out = [
            'id' => $run->id,
            'workflowId' => $run->workflow_id,
            'state' => $run->state,
            'finished' => $run->finished(),
            'trigger' => ['kind' => $run->trigger['kind'] ?? 'manual', 'summary' => $run->trigger['summary'] ?? ''],
            'user' => $run->startedByName(),
            'startedAt' => $run->started_at->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'seconds' => (int) $run->started_at->diffInSeconds($run->finished_at ?? now()),
            'failedBecause' => $failed['message'] ?? null,
            'url' => $context->url("/workflows/{$run->workflow_id}?run={$run->id}"),
        ];
        if (! $full) {
            return $out;
        }

        $nodes = collect($run->graph['nodes'] ?? [])->keyBy('id');
        $logs = $run->logs ?? [];
        $out['trigger']['data'] = (object) ($run->trigger['data'] ?? []);
        $out['steps'] = collect($steps)->map(fn (array $step, string $id) => array_filter([
            'id' => $id,
            'kind' => $nodes[$id]['kind'] ?? null,
            'title' => ($nodes[$id]['title'] ?? '') !== '' ? $nodes[$id]['title'] : WorkflowText::kindLabel((string) ($nodes[$id]['kind'] ?? '')),
            'state' => $step['state'] ?? 'pending',
            'message' => $step['message'] ?? null,
            'leftBy' => $step['port'] ?? null,
            'unhandled' => ($step['unhandled'] ?? false) ? true : null,
            'jobs' => ($step['jobs'] ?? []) === [] ? null : array_values($step['jobs']),
            'until' => isset($step['until']) && ($step['state'] ?? null) === 'waiting' ? self::time($step['until']) : null,
            'startedAt' => isset($step['startedAt']) ? self::time($step['startedAt']) : null,
            'finishedAt' => isset($step['finishedAt']) ? self::time($step['finishedAt']) : null,
        ], fn ($v) => $v !== null))->values()->all();
        $out['notReached'] = $nodes->keys()->diff(array_keys($steps))->values()->all();
        $out['logs'] = array_map(fn (array $l) => [
            'at' => CarbonImmutable::createFromTimestampMs($l['t'])->toIso8601String(),
            'step' => $l['node'],
            'level' => $l['level'],
            'message' => $l['message'],
        ], $logLines > 0 ? array_slice($logs, -$logLines) : []);
        $out['logLines'] = count($logs);

        return $out;
    }

    /**
     * The steps a run can reach from the trigger, in the order it would.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, true>
     */
    private static function reached(array $graph): array
    {
        $order = [];
        $queue = collect($graph['nodes'])->where('kind', 'trigger')->pluck('id')->all();
        while (($id = array_shift($queue)) !== null) {
            if (isset($order[$id])) {
                continue;
            }
            $order[$id] = true;
            foreach ($graph['edges'] as $edge) {
                if ($edge['source'] === $id && ! isset($order[$edge['target']])) {
                    $queue[] = $edge['target'];
                }
            }
        }

        return $order;
    }

    private static function time(mixed $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp((int) $timestamp)->toIso8601String();
    }
}
