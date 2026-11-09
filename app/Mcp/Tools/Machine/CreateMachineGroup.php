<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\MachineGroup;

/**
 * Group some machines under a name, as the machines page does.
 */
final class CreateMachineGroup extends Tool
{
    public function name(): string
    {
        return 'create_machine_group';
    }

    public function title(): string
    {
        return 'Create a machine group';
    }

    public function description(): string
    {
        return 'Creates a machine group: a name (up to 60 characters) and the machines in it (ids or names; may be empty). A machine is in one group at most, so any machine you list that is already in another group moves to this one; the answer says which moved. Give policy (a machine policy’s id or name) to have its machines follow that policy, unless one picked its own; without it they follow the workspace’s default. Returns the group with its id.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('The group’s name.', maxLength: 60),
            'machines' => Schema::array(Schema::machine('A machine: its id or name.'), 'The machines to put in it.', 500),
            'policy' => Schema::string('The machine policy its machines follow: an id or name from list_machine_policies. Leave it out for the workspace’s default.', maxLength: 60),
        ], ['name']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(ServerWork::trimmed($arguments, 'name'), [
            'name' => ['required', 'string', 'max:60'],
            'machines' => ['sometimes', 'array', 'list', 'max:500'],
            'machines.*' => [ServerWork::reference()],
            'policy' => ['sometimes', 'nullable'],
        ]);

        $machines = MachineGroups::machines($context, $data['machines'] ?? []);
        $moved = $machines->filter(fn (Machine $m) => $m->machine_group_id !== null);
        $from = $context->workspace->machineGroups()->whereIn('id', $moved->pluck('machine_group_id'))->pluck('name', 'id');

        $group = new MachineGroup;
        $policy = MachineGroups::policy($context, $data['policy'] ?? null);
        MachineGroups::save($context, $group, trim($data['name']), array_values($machines->map(fn (Machine $m) => $m->id)->all()), $policy);
        $context->record('machine_group.create', $group->name, meta: ['machines' => $machines->count()]);

        return [
            'group' => MachineGroups::present($group, $context),
            'moved' => $moved->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name, 'fromGroup' => $from[$m->machine_group_id] ?? null])->values()->all(),
        ];
    }
}
