<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Run a one-off shell command inside a container, as the service page's
 * console does. The console needs two-factor authentication on the
 * account; this connection's "Run commands" permission stands in for
 * unlocking it with a code. The command is kept, word for word, in the
 * workspace's activity.
 */
final class RunCommand extends Tool
{
    /** How long the command may run on the machine, as the console allows. */
    private const TIMEOUT = 60;

    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'run_command';
    }

    public function title(): string
    {
        return 'Run a command in a container';
    }

    public function description(): string
    {
        return 'Runs a shell command inside a container service on a machine, like the console on its page (one-off and non-interactive, no TTY, up to 60 seconds), and returns its output and exit code. Commands can change or delete anything in the container, so show the person the exact command and get their go-ahead before running anything that isn’t read-only. Every command is recorded, word for word, in the workspace’s activity. '
            .'Needs: this connection allowed to run commands, two-factor authentication on the person’s account, a managed (adopted) container, and the machine online. It waits for the output (wait, default 25 seconds); a command that takes longer keeps running: follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service('The container service: its key, name or label (as list_services shows).'),
            'command' => Schema::string('The shell command to run, like “redis-cli ping” or “ls -la /data”.', maxLength: 2000),
            'wait' => ServiceWork::waitSchema(McpContext::MAX_WAIT_SECONDS),
        ], ['machine', 'service', 'command']);
    }

    public function scope(): string
    {
        return Scopes::COMMANDS;
    }

    /** A command can change or delete anything in the container. */
    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'command' => ['required', 'string', 'max:2000'],
            'wait' => ServiceWork::waitRules(),
        ]);
        $command = trim($data['command']);
        if ($command === '') {
            throw new ToolError('Invalid arguments: The command field is required.');
        }
        [$machine, $service] = ServiceWork::find($context, $data);

        // In the service page's order.
        ServiceWork::requireManaged($service);
        ServiceWork::requireContainer($service);
        if (! $context->user->hasEnabledTwoFactorAuthentication()) {
            throw new ToolError('Turn on two-factor authentication to use the console: the person can do that in ServerOS under Settings → Security. Commands can’t run until then.');
        }

        $key = (string) $service['key'];
        $job = $this->work->queue($context, $machine, 'service_exec', ['type' => 'service_exec', 'service' => $key, 'command' => $command, 'timeout_secs' => self::TIMEOUT], $key);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? McpContext::MAX_WAIT_SECONDS));
        $exit = $job->result['exit_code'] ?? null;

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'command' => $command,
            'jobId' => $job->id,
            'state' => $job->state,
            'finished' => ! $job->isActive(),
            'exitCode' => is_numeric($exit) ? (int) $exit : null,
            'output' => array_slice(ServiceView::output($job), -500),
            'error' => $job->error,
            'note' => $job->isActive() ? 'Still running. Call get_job with this jobId for the rest of the output and the exit code.' : null,
        ];
    }
}
