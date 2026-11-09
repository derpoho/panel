<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\WorkflowRun;

/**
 * Recent runs of a workflow, or of all of them, newest first.
 */
final class ListWorkflowRuns extends Tool
{
    private const STATES = ['running', 'succeeded', 'failed', 'cancelled'];

    public function name(): string
    {
        return 'list_workflow_runs';
    }

    public function title(): string
    {
        return 'List workflow runs';
    }

    public function description(): string
    {
        return 'Lists runs newest first: of one workflow (its Runs view), or of every workflow in the workspace when workflow is left out. Each has its id (for get_workflow_run), the workflow, state (running, succeeded, failed or cancelled), what started it (kind: manual, schedule, webhook or the event; and a summary), who ran it by hand, start and finish times, seconds, and for failed runs the step message that failed it. '
            .'Filter by state; page with before (a run id: only older runs). Runs are kept '.WorkflowRun::KEEP_DAYS.' days.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'workflow' => Schema::string('The workflow (id or name). Leave out for every workflow’s runs.', maxLength: 120),
            'state' => Schema::string('Only runs in this state.', self::STATES),
            'before' => Schema::integer('Only runs older than this run id (for the next page).', 1),
            'limit' => Schema::integer('How many (1–50, default 20).', 1, 50),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'workflow' => ['sometimes', Workflows::reference()],
            'state' => ['sometimes', 'string', 'in:'.implode(',', self::STATES)],
            'before' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);
        $workflow = isset($data['workflow']) ? Workflows::find($context, $data['workflow']) : null;
        $names = $context->workspace->workflows()->pluck('name', 'id');

        $runs = WorkflowRun::query()
            ->with('user:id,name')
            ->when($workflow, fn ($q) => $q->where('workflow_id', $workflow->id), fn ($q) => $q->whereIn('workflow_id', $names->keys()))
            ->when(isset($data['state']), fn ($q) => $q->where('state', $data['state']))
            ->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
            ->latest('id')
            ->limit($limit + 1)
            ->get();
        $more = $runs->count() > $limit;
        $runs = $runs->take($limit);

        return [
            'workflow' => $workflow ? ['id' => $workflow->id, 'name' => $workflow->name] : null,
            'runs' => $runs->map(fn (WorkflowRun $r) => [
                ...WorkflowView::run($r, $context, false),
                'workflow' => $names[$r->workflow_id] ?? null,
            ])->values()->all(),
            'more' => $more,
            'nextBefore' => $more ? $runs->last()?->id : null,
        ];
    }
}
