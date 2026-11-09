<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\MachineGroup;

/**
 * The workspace's machine groups and what's in each.
 */
final class ListMachineGroups extends Tool
{
    public function name(): string
    {
        return 'list_machine_groups';
    }

    public function title(): string
    {
        return 'List machine groups';
    }

    public function description(): string
    {
        return 'Lists the workspace’s machine groups (named sets of machines, shown as one row on the machines page) with each group’s id, name and machines (id, name, environment, status), and how many machines are in no group. A machine is in one group at most.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $groups = $context->workspace->machineGroups()->orderBy('name')->get();

        return [
            'groups' => $groups->map(fn (MachineGroup $group) => MachineGroups::present($group, $context))->all(),
            'ungroupedMachines' => $context->workspace->machines()->whereNull('machine_group_id')->count(),
            'url' => $context->url('/machines'),
        ];
    }
}
