<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Send a deploy again exactly as it was sent, as a new job, as Retry on
 * the machine page does. One still running can only be retried once it
 * has gone quiet.
 */
final class RetryJob extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'retry_job';
    }

    public function title(): string
    {
        return 'Retry a deploy';
    }

    public function description(): string
    {
        return 'Sends a deploy job again exactly as it was first sent (same code, settings and secrets), as a new job, like Retry on the machine page; the new version replaces what is running once it is healthy. Only deploys can be retried (for other jobs, do the action again, like restart_service). A deploy still queued or running can only be retried once it is stale (no news for 10 minutes); it is then marked cancelled first. '
            .'Tell the person what will be deployed again and where before retrying. The machine must be online. Returns the new job: follow it with get_job (wait, up to 25 seconds, answers sooner if it finishes).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'job' => Schema::string('The id of the deploy job to send again.', maxLength: 64),
            'wait' => ServiceWork::waitSchema(),
        ], ['job']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'job' => ['required', 'string', 'max:64'],
            'wait' => ServiceWork::waitRules(),
        ]);
        $job = ServiceWork::job($context, $data['job']);

        // In DaemonJobController::retry's order, with its words.
        if ($job->kind !== 'deploy') {
            throw new ToolError("Only deploys can be retried. This is a {$job->kind} job: do the action again instead.");
        }
        if (! $job->isRetryable()) {
            throw new ToolError('This deploy was sent before ServerOS kept deploy settings, so it can’t be retried. Deploy it again from the library.');
        }
        if ($job->isActive() && ! $job->isStale()) {
            throw new ToolError('This deploy is still running.');
        }
        if (($reason = $this->work->unavailable($job->machine, $job->kind)) !== null) {
            throw new ToolError($reason);
        }

        $replaced = $job->isActive();
        if ($replaced) {
            CancelJob::stopWaiting($job);
        }
        $retry = $this->work->retry($context, $job);

        return [
            'job' => ServiceView::job($context, $context->waitForJob($retry, (int) ($data['wait'] ?? 0)), 20),
            'retriedFrom' => ['id' => $job->id, 'state' => $job->state, 'cancelledFirst' => $replaced],
        ];
    }
}
