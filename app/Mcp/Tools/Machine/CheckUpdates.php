<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\NotAudited;

/**
 * Ask a machine's package manager what updates are waiting, as the
 * updates dialog does when it opens. Nothing is installed.
 */
final class CheckUpdates extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'check_updates';
    }

    public function title(): string
    {
        return 'Check for operating system updates';
    }

    public function description(): string
    {
        return 'Asks the machine’s package manager (apt, dnf or yum) which operating system updates are waiting. Installs nothing. Runs as a job on the machine and usually takes 10–60 seconds: pass wait (up to 25 seconds) to get the result in this answer, or call get_updates_result with the jobId. The result lists each package (name, from and to versions, whether it’s a security update) and whether a reboot is needed. The machine must be online.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'wait' => ServerWork::waitSchema(),
        ], ['machine']);
    }

    /**
     * It changes nothing in ServerOS, but it runs the package manager as
     * root on the server, which the dashboard keeps to people who manage
     * machines: a change, not something a read-only connection can start.
     */
    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    #[NotAudited('A check for updates; installing them is recorded')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServerWork::reference()],
            'wait' => ServerWork::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);
        ServerWork::throttle($context, 'check-updates', 10);

        $job = $this->work->queue($context, $machine, 'package_updates', ['type' => 'package_updates', 'apply' => false, 'security_only' => false], 300);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...MachineView::updatesJob($machine, $job),
        ];
    }
}
