<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Workflows\WorkflowEngine;

/**
 * One run as the editor's Runs view shows it: each step's state and
 * message, and the log.
 */
final class GetWorkflowRun extends Tool
{
    public function name(): string
    {
        return 'get_workflow_run';
    }

    public function title(): string
    {
        return 'Get a workflow run';
    }

    public function description(): string
    {
        return 'Returns one run of a workflow, as its Runs view shows it: state (running, succeeded, failed or cancelled), what started it (kind, summary and the data the trigger carried), who ran it by hand, when it started and finished, why it failed (failedBecause), '
            .'each step it reached in order with its state (pending, running, waiting, succeeded, failed, cancelled), its message, which port it left by, the machine jobs it is waiting on (ids for get_job), and until when a wait lasts; the steps it never reached (notReached); and the log, newest last (logs lines, default 100). '
            .'A run waiting on a machine, a wait or a monitor check moves on by itself: call again to follow it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'run' => Workflows::runSchema(),
            'workflow' => Schema::string('Optional: the workflow it should belong to (id or name), as a check.', maxLength: 120),
            'logs' => Schema::integer('How many log lines to return, newest last (0–'.WorkflowEngine::LOG_LIMIT.', default 100).', 0, WorkflowEngine::LOG_LIMIT),
        ], ['run']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'run' => ['required', 'integer', 'min:1'],
            'workflow' => ['sometimes', Workflows::reference()],
            'logs' => ['sometimes', 'integer', 'min:0', 'max:'.WorkflowEngine::LOG_LIMIT],
        ]);

        $workflow = isset($data['workflow']) ? Workflows::find($context, $data['workflow']) : null;
        $run = Workflows::run($context, $data['run'], $workflow);
        $run->load(['user:id,name', 'workflow:id,name']);

        return [
            ...WorkflowView::run($run, $context, true, (int) ($data['logs'] ?? 100)),
            'workflow' => ['id' => $run->workflow->id, 'name' => $run->workflow->name],
        ];
    }
}
