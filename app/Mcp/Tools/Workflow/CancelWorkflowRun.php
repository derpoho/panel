<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;
use App\Support\Workflows\WorkflowEngine;

/**
 * Stop a run that's still going, as the Cancel button on a run does.
 */
final class CancelWorkflowRun extends Tool
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function name(): string
    {
        return 'cancel_workflow_run';
    }

    public function title(): string
    {
        return 'Cancel a workflow run';
    }

    public function description(): string
    {
        return 'Cancels a run that’s still going, like the Cancel button on a run: steps not done yet (running, waiting or pending) are marked cancelled and nothing more of the run happens. It can’t be resumed (run_workflow starts a new one), and work already sent to a machine (a deploy, a restart, a command, a backup) carries on there: follow those jobs with get_job. '
            .'Ask the person first unless they asked you to stop it, then pass confirm set to the workflow’s name exactly. A run that already finished is left as it is.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'run' => Workflows::runSchema(),
            'workflow' => Schema::string('Optional: the workflow it should belong to (id or name), as a check.', maxLength: 120),
            'confirm' => Schema::confirm('the workflow’s name'),
        ], ['run', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'run' => ['required', 'integer', 'min:1'],
            'workflow' => ['sometimes', Workflows::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);

        $workflow = isset($data['workflow']) ? Workflows::find($context, $data['workflow']) : null;
        $run = Workflows::run($context, $data['run'], $workflow);
        $workflow ??= $run->workflow;
        if ($run->finished()) {
            AuditContext::nothingChanged();

            return [
                'run' => WorkflowView::run($run->load('user:id,name'), $context),
                'note' => "Run #{$run->id} already {$run->state}; nothing to cancel.",
            ];
        }
        Workflows::confirm($data, $workflow, 'stops the run');

        $context->within(fn () => $this->engine->cancel($run, $context->user));
        $run->refresh()->load('user:id,name');
        $jobs = collect($run->steps)->flatMap(fn (array $s): array => is_array($s['jobs'] ?? null) ? $s['jobs'] : [])->values()->all();

        return [
            'run' => WorkflowView::run($run, $context),
            'note' => "Cancelled run #{$run->id} of “{$workflow->name}”."
                .($jobs === [] ? '' : ' Machine work it had started carries on there; get_job follows it: '.implode(', ', $jobs).'.'),
        ];
    }
}
