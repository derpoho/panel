<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\WorkflowRun;

/**
 * The workspace's workflows, as the Workflows page lists them.
 */
final class ListWorkflows extends Tool
{
    public function name(): string
    {
        return 'list_workflows';
    }

    public function title(): string
    {
        return 'List workflows';
    }

    public function description(): string
    {
        return 'Lists the workspace’s workflows (automations: a trigger and steps), by name, like the Workflows page: id, name, description, whether it’s on (enabled), its trigger (the event, and in words like “Every day at 03:00 UTC” or “A monitor goes down: Shop”), how many steps, its last run (id, state, time), runs and failed runs in the last 7 days, and when it runs next (scheduled ones that are on). '
            .'`planAllows` says whether the plan includes workflows (Pro or Business). Use get_workflow for one workflow’s steps and problems, list_workflow_runs for its runs, and list_workflow_templates to start a new one.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'enabled' => Schema::boolean('Only workflows that are on (true) or off (false). Leave out for all.'),
            'trigger' => Schema::string('Only workflows started by this event (like “schedule”, “webhook”, “monitor.down”).', maxLength: 40),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'enabled' => ['sometimes', 'boolean'],
            'trigger' => ['sometimes', 'string', 'max:40'],
        ]);

        $workspace = $context->workspace;
        $workflows = $workspace->workflows()->orderBy('name')->get();
        $runs = WorkflowRun::query()
            ->whereIn('workflow_id', $workflows->modelKeys())
            ->where('started_at', '>=', now()->subDays(7))
            ->get(['id', 'workflow_id', 'state', 'started_at'])
            ->groupBy('workflow_id');
        $words = WorkflowWords::for($workspace);

        $shown = $workflows
            ->when(isset($data['enabled']), fn ($c) => $c->where('enabled', (bool) $data['enabled']))
            ->when(isset($data['trigger']), fn ($c) => $c->where('trigger', $data['trigger']));

        return [
            'workflows' => $shown->map(fn ($w) => WorkflowView::row($w, $runs[$w->id] ?? collect(), $words, $context))->values()->all(),
            'count' => $shown->count(),
            'total' => $workflows->count(),
            'limit' => Workflows::LIMIT,
            'planAllows' => $workspace->hasAutomations(),
            'url' => $context->url('/workflows'),
        ];
    }
}
