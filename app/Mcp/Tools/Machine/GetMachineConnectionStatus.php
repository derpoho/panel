<?php

namespace App\Mcp\Tools\Machine;

use App\Enums\WorkspacePermission;
use App\Http\Controllers\DaemonConnectController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonEnrolment;
use App\Support\Machines\MachinePolicies;

/**
 * How a server being connected is getting on, as the Connect server
 * dialog follows it.
 */
final class GetMachineConnectionStatus extends Tool
{
    private const NEXT = [
        'waiting' => 'The install command hasn’t been run yet (or is still running). Check again in a little while.',
        'expired' => 'The command expired before the machine enrolled. add_machine gives a new one.',
        'enrolled' => 'The daemon enrolled and is connecting to ServerOS.',
        'connected' => 'Connected; the first scan of its services is under way.',
        'discovered' => 'Connected and scanned. get_machine shows it.',
    ];

    public function name(): string
    {
        return 'get_machine_connection_status';
    }

    public function title(): string
    {
        return 'Check on a machine being connected';
    }

    public function description(): string
    {
        return 'Follows a machine being connected with add_machine, by the enrolmentId it returned. state is waiting (command not run yet), expired (not used within 15 minutes), enrolled (daemon installed, connecting), connected (reporting, first scan under way) or discovered (done). Once enrolled it gives the new machine’s id and name, and once discovered how many services and listening ports were found.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'enrolment' => Schema::integer('The enrolmentId from add_machine.', 1),
        ], ['enrolment']);
    }

    /** The dashboard keeps it to people who connect machines. */
    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMachines;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['enrolment' => ['required', 'integer', 'min:1']]);
        ServerWork::throttle($context, 'connect-status', 60);

        $enrolment = DaemonEnrolment::query()->where('workspace_id', $context->workspace->id)->find((int) $data['enrolment']);
        if ($enrolment === null) {
            throw new ToolError("No machine connection #{$data['enrolment']} in {$context->workspace->name}. add_machine starts one.");
        }

        $machine = $enrolment->machine_id === null ? null : $context->workspace->machines()->find($enrolment->machine_id);
        // A policy with discovery off is done once it's connected.
        $discovery = $machine === null || MachinePolicies::for($machine)->discovery;
        $state = DaemonConnectController::state($enrolment, $machine, $discovery);

        return [
            'enrolmentId' => $enrolment->id,
            'state' => $state,
            'expiresAt' => $enrolment->expires_at->toIso8601String(),
            'machine' => $machine === null ? null : [
                'id' => $machine->id,
                'name' => $machine->name,
                'url' => $context->url("/machines/{$machine->id}"),
            ],
            'services' => $machine === null ? 0 : $machine->services_count,
            'listeners' => $machine === null ? 0 : count($machine->inventory['listeners'] ?? []),
            'discovery' => $discovery,
            'next' => self::NEXT[$state],
        ];
    }
}
