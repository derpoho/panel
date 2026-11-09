<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;

/**
 * Follow a server job: any work ServerOS sent to one of the workspace's
 * machines (a deploy, a restart, a backup, an update, a command…).
 */
final class GetJob extends Tool
{
    public function name(): string
    {
        return 'get_job';
    }

    public function title(): string
    {
        return 'Get a job';
    }

    public function description(): string
    {
        return 'Returns a job on a machine by its id (the tools that start work return it): kind, state (queued, running, succeeded, failed, cancelled, timed_out or refused), finished, phase and progress (0–100) while it runs, the last log lines (log_lines, default 40, up to 500; logLines is how many there are), result (what the job produced, like a service_logs job’s lines or a command’s exit_code), error, who started it and when. '
            .'Pass wait (up to 25 seconds) to answer as soon as it finishes. A job that has been queued or running with no news for 10 minutes is stale: the machine may never report back (cancel_job stops waiting on it). Works for any job on the workspace’s machines.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'job' => Schema::string('The job’s id.', maxLength: 64),
            'log_lines' => Schema::integer('How many of the latest log lines to include (0–500, default 40).', 0, 500),
            'wait' => ServiceWork::waitSchema(),
        ], ['job']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'job' => ['required', 'string', 'max:64'],
            'log_lines' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'wait' => ServiceWork::waitRules(),
        ]);
        $job = $context->waitForJob(ServiceWork::job($context, $data['job']), (int) ($data['wait'] ?? 0));

        return ServiceView::job($context, $job, (int) ($data['log_lines'] ?? 40));
    }
}
