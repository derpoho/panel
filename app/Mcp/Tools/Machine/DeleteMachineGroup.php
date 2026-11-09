<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Machines\MachinePolicies;

/**
 * Remove a machine group; its machines go back to the main list.
 */
final class DeleteMachineGroup extends Tool
{
    public function name(): string
    {
        return 'delete_machine_group';
    }

    public function title(): string
    {
        return 'Delete a machine group';
    }

    public function description(): string
    {
        return 'Deletes a machine group. Its machines stay, back in no group; nothing on the machines changes. Ask the person before deleting, then pass confirm set to the group’s name exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'group' => Schema::string('The group: its id or name (as list_machine_groups shows).', maxLength: 120),
            'confirm' => Schema::confirm('the group’s name'),
        ], ['group', 'confirm']);
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
        $data = $this->validate($arguments, [
            'group' => ['required', ServerWork::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $group = MachineGroups::find($context, $data['group']);
        ServerWork::confirm($data, $group->name, 'group');

        $members = $context->workspace->machines()->where('machine_group_id', $group->id)->pluck('id');
        $machines = $members->count();
        // Its machines go back to the default policy, unless they picked their own.
        MachinePolicies::whileChanging($context->workspace, $members, fn () => $group->delete());
        $context->record('machine_group.delete', $group->name);

        return [
            'deleted' => ['id' => $group->id, 'name' => $group->name],
            'machinesUngrouped' => $machines,
        ];
    }
}
