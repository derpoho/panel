<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Support\Machines\MachinePolicies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Machine groups as the dashboard keeps them: found by id or name inside
 * the workspace, and saved so a machine sits in one group at most.
 */
final class MachineGroups
{
    /** A group in this workspace, by id or name (any case). */
    public static function find(McpContext $context, mixed $reference): MachineGroup
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which group: its id or name. list_machine_groups shows them.');
        }

        $query = $context->workspace->machineGroups();
        if (ctype_digit($reference) && ($group = (clone $query)->find((int) $reference)) !== null) {
            return $group;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several groups are called “{$reference}”: ".$matches->map(fn (MachineGroup $g) => "#{$g->id}")->implode(', ').'. Use the id.');
        }

        throw new ToolError("No machine group “{$reference}” in {$context->workspace->name}. list_machine_groups shows them.");
    }

    /**
     * The machines named, each resolved inside the workspace.
     *
     * @param  list<mixed>  $references
     * @return Collection<int, Machine>
     */
    public static function machines(McpContext $context, array $references): Collection
    {
        return collect($references)->map(fn ($reference) => $context->machine($reference))->unique('id')->values();
    }

    /**
     * Save the group and point exactly these machines at it, taking them
     * out of any other group, as MachineGroupController does. Machines whose
     * policy changes with it are sent their new one.
     *
     * @param  list<int>  $machineIds
     * @param  int|null|false  $policyId  the policy its machines follow; null for the default, false to leave it
     */
    public static function save(McpContext $context, MachineGroup $group, string $name, array $machineIds, int|null|false $policyId = false): void
    {
        $workspace = $context->workspace;
        $members = $group->exists ? $workspace->machines()->where('machine_group_id', $group->id)->pluck('id')->all() : [];

        MachinePolicies::whileChanging($workspace, [...$members, ...$machineIds], fn () => DB::transaction(function () use ($workspace, $group, $name, $machineIds, $policyId) {
            $group->forceFill([
                'workspace_id' => $workspace->id,
                'name' => $name,
                ...($policyId === false ? [] : ['machine_policy_id' => $policyId]),
            ])->save();

            $workspace->machines()->where('machine_group_id', $group->id)->whereNotIn('id', $machineIds)
                ->update(['machine_group_id' => null]);
            $workspace->machines()->whereIn('id', $machineIds)->update(['machine_group_id' => $group->id]);
        }));
    }

    /**
     * The policy a group's machines follow, from a tool's input: a policy's
     * id or name, or "default" (or null) to follow the workspace's default.
     */
    public static function policy(McpContext $context, mixed $reference): ?int
    {
        if ($reference === null || mb_strtolower(trim((string) $reference)) === 'default') {
            return null;
        }

        return MachinePolicyRefs::find($context, $reference)->id;
    }

    /**
     * The group with its machines, for answers.
     *
     * @return array<string, mixed>
     */
    public static function present(MachineGroup $group, McpContext $context): array
    {
        $machines = $context->workspace->machines()->where('machine_group_id', $group->id)->orderBy('name')->get();

        return [
            'id' => $group->id,
            'name' => $group->name,
            // The policy its machines follow, unless one picked its own.
            'policy' => $group->machine_policy_id === null ? 'default' : $group->policy?->name,
            'machineCount' => $machines->count(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'environment' => $machine->environment->value,
                'status' => Present::connection($machine),
            ])->all(),
        ];
    }
}
