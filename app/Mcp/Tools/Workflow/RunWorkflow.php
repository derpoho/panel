<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Workflow;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowInputs;
use App\Support\Workflows\WorkflowSteps;

/**
 * Run a workflow now, as the editor's Run button does: on or off, with
 * the trigger pretending to be about what it names.
 */
final class RunWorkflow extends Tool
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function name(): string
    {
        return 'run_workflow';
    }

    public function title(): string
    {
        return 'Run a workflow now';
    }

    public function description(): string
    {
        return 'Runs a workflow now, by hand, like the Run button in its editor: whether or not it’s on, so it can be tried before it is. Its steps really happen (restarts, deploys, backups, commands, alerts, HTTP calls), so say what it will do and check with the person first. '
            .'An event trigger pretends the event happened to what its filters name (or the first machine and service), with data.test true; a webhook trigger gets ref “refs/heads/main”. It must have no problems (get_workflow), the plan must include workflows, and a workflow runs at most '.Workflow::RUNS_PER_HOUR.' times an hour. A workflow with a command step needs a connection allowed to run commands. '
            .'Returns the run (id, state, each step’s state and message, the log). Steps that wait for a machine, a wait or a monitor check keep the run going after this answers: pass wait (seconds, up to 25) to wait for it to finish first, and follow it with get_workflow_run (and the jobs it lists with get_job).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'workflow' => Workflows::workflowSchema(),
            'wait' => Schema::integer('Seconds to wait for the run to finish before answering (0–25, default 0). Runs that take longer keep going.', 0, McpContext::MAX_WAIT_SECONDS),
        ], ['workflow']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'workflow' => ['required', Workflows::reference()],
            'wait' => ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS],
        ]);

        $workspace = $context->workspace;
        $workflow = Workflows::find($context, $data['workflow']);
        if (! $workspace->hasAutomations()) {
            throw new ToolError('Workflows come with Pro.');
        }
        $graph = WorkflowSteps::clean($workflow->graph);
        $problems = WorkflowSteps::problems($graph, $workspace);
        if ($problems !== []) {
            throw new ToolError('Fix the steps marked with a warning first. '.Workflows::problemText(Workflows::problems($problems, $graph)));
        }
        Workflows::requireCommands($context, $graph, 'running');

        $run = $context->within(fn () => $this->engine->start($workflow, 'manual', "Run by {$context->user->name}", WorkflowInputs::sampleData($workflow), $context->user));
        if ($run === null) {
            throw new ToolError('It has run '.Workflow::RUNS_PER_HOUR.' times in the last hour; try again later.');
        }

        $deadline = microtime(true) + (int) ($data['wait'] ?? 0);
        while (! $run->finished() && microtime(true) < $deadline) {
            usleep(500_000);
            $run->refresh();
        }
        $run->load('user:id,name');

        return [
            'run' => WorkflowView::run($run, $context),
            'note' => $run->finished()
                ? "Run #{$run->id} {$run->state}."
                : "Run #{$run->id} is still going: follow it with get_workflow_run.",
        ];
    }
}
