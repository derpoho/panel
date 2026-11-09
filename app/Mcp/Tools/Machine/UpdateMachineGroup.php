<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Support\Audit\AuditContext;

/**
 * Rename a machine group or change which machines are in it.
 */
final class UpdateMachineGroup extends Tool
{
    public function name(): string
    {
        return 'update_machine_group';
    }

    public function title(): string
    {
        return 'Change a machine group';
    }

    public function description(): string
    {
        return 'Renames a machine group and/or changes its machines. machines replaces the whole membership (an empty list empties it); add and remove change it a few at a time (applied after machines, if both are given). Leave out what shouldn’t change. A machine is in one group at most, so adding one that is in another group moves it here; removed machines go back to no group. Returns the group as it is now and which machines moved in from other groups.';
    }

    public function inputSchema(): array
    {
        $machine = Schema::machine('A machine: its id or name.');

        return Schema::object([
            'group' => Schema::string('The group: its id or name (as list_machine_groups shows).', maxLength: 120),
            'name' => Schema::string('A new name for it (up to 60 characters).', maxLength: 60),
            'machines' => Schema::array($machine, 'Exactly the machines it should have.', 500),
            'add' => Schema::array($machine, 'Machines to put in it.', 500),
            'remove' => Schema::array($machine, 'Machines to take out of it.', 500),
            'policy' => Schema::string('The machine policy its machines follow (unless one picked its own): an id or name from list_machine_policies, or "default" to follow the workspace’s default.', maxLength: 60),
        ], ['group']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(ServerWork::trimmed($arguments, 'name'), [
            'group' => ['required', ServerWork::reference()],
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'machines' => ['sometimes', 'array', 'list', 'max:500'],
            'machines.*' => [ServerWork::reference()],
            'add' => ['sometimes', 'array', 'list', 'max:500'],
            'add.*' => [ServerWork::reference()],
            'remove' => ['sometimes', 'array', 'list', 'max:500'],
            'remove.*' => [ServerWork::reference()],
            'policy' => ['sometimes', 'nullable'],
        ]);
        if (! isset($data['name']) && ! isset($data['machines']) && ! isset($data['add']) && ! isset($data['remove']) && ! array_key_exists('policy', $data)) {
            throw new ToolError('Give a new name, machines, add, remove or policy: there’s nothing to change.');
        }

        $group = MachineGroups::find($context, $data['group']);
        $current = $context->workspace->machines()->where('machine_group_id', $group->id)->pluck('id')->all();

        $ids = isset($data['machines']) ? MachineGroups::machines($context, $data['machines'])->pluck('id')->all() : $current;
        $added = MachineGroups::machines($context, $data['add'] ?? []);
        $removed = MachineGroups::machines($context, $data['remove'] ?? [])->pluck('id')->all();
        $ids = array_values(array_diff(array_unique([...$ids, ...$added->pluck('id')->all()]), $removed));

        // Who comes in from another group, to say so.
        $moved = $context->workspace->machines()->whereIn('id', $ids)
            ->whereNotNull('machine_group_id')->where('machine_group_id', '!=', $group->id)
            ->get();
        $from = $context->workspace->machineGroups()->whereIn('id', $moved->pluck('machine_group_id'))->pluck('name', 'id');

        $name = isset($data['name']) ? trim($data['name']) : $group->name;
        $before = $group->name;
        $policyBefore = $group->machine_policy_id;
        $policy = array_key_exists('policy', $data) ? MachineGroups::policy($context, $data['policy']) : false;
        MachineGroups::save($context, $group, $name, $ids, $policy);

        $sorted = fn (array $list): array => collect($list)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $regrouped = $sorted($current) !== $sorted($ids);
        $repolicied = $policy !== false && $policy !== $policyBefore;
        if ($before !== $name || $regrouped) {
            $context->record('machine_group.update', $name, meta: array_filter([
                'from' => $before !== $name ? $before : null,
                'machines' => $regrouped ? count($ids) : null,
            ], fn ($v) => $v !== null));
        }
        if ($repolicied) {
            $context->record('machine_group.update', $name, meta: ['policy' => $policy === null ? __('the default') : $group->policy()->value('name')]);
        }
        if ($before === $name && ! $regrouped && ! $repolicied) {
            AuditContext::nothingChanged();
        }

        return [
            'group' => MachineGroups::present($group->refresh(), $context),
            'moved' => $moved->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name, 'fromGroup' => $from[$m->machine_group_id] ?? null])->values()->all(),
            'removed' => count(array_diff($current, $ids)),
        ];
    }
}
