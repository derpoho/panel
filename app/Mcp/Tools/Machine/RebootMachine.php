<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Restart a machine, as the updates dialog does. Alerts and workflows
 * about it are held for a few minutes, so the reboot doesn't page anyone.
 */
final class RebootMachine extends Tool
{
    /** How long alerts are held while it restarts, as MachineUpdatesController. */
    private const QUIET_MINUTES = 10;

    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'reboot_machine';
    }

    public function title(): string
    {
        return 'Reboot a machine';
    }

    public function description(): string
    {
        return 'Restarts the machine. Every service on it goes offline for a minute or two, so ask the person first and pass confirm set to the machine’s name exactly. Refused while updates are being installed on it (wait for install_updates to finish); if a deploy or backup is running (get_machine’s recentJobs), wait for that too. Unless it is already in maintenance, it is put in maintenance for 10 minutes (not shown on its status page) so the restart doesn’t page anyone or start workflows; reason sets what that window says. The machine must be online. Returns the reboot job; with wait (seconds) it waits for the daemon to take it. get_machine shows when it reports again.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'confirm' => Schema::confirm('the machine’s name'),
            'reason' => Schema::string('What the maintenance window says while it restarts (default “Restarting after updates”).', maxLength: 200),
            'wait' => ServerWork::waitSchema(),
        ], ['machine', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(ServerWork::trimmed($arguments, 'reason'), [
            'machine' => ['required', ServerWork::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:200'],
            'wait' => ServerWork::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);
        ServerWork::confirm($data, $machine->name, 'machine', 'Restarting takes every service on it offline for a minute or two.');
        // The updates dialog only offers a restart once an install is done:
        // one cut short can leave the package manager half-way.
        if (($installing = ServerWork::installing($machine)) !== null) {
            throw new ToolError("Updates are being installed on {$machine->name} (job {$installing->id}). Restarting now could leave packages half-installed: wait for it to finish (get_updates_result), then reboot. Nothing was changed.");
        }
        ServerWork::throttle($context, 'reboot', 3);

        $job = $this->work->queue($context, $machine, 'reboot', ['type' => 'reboot'], 60);
        $held = ! $machine->inMaintenance();
        if ($held) {
            $machine->forceFill([
                'maintenance_until' => now()->addMinutes(self::QUIET_MINUTES),
                'maintenance_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : 'Restarting after updates',
                'maintenance_public' => false,
            ])->save();
        }
        $context->record('machine.reboot', $machine->name, $machine);

        return [
            'job' => Present::job($context->waitForJob($job, (int) ($data['wait'] ?? 0))),
            'maintenance' => [
                'until' => $machine->maintenance_until->toIso8601String(),
                'reason' => $machine->maintenance_reason,
                'startedNow' => $held,
            ],
            'note' => "Restarting {$machine->name}. Alerts are held until ".$machine->maintenance_until->toIso8601String().'.',
        ];
    }
}
