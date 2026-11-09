<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MachineGroupController extends Controller
{
    /**
     * Group some of the workspace's machines under a name, and optionally
     * a policy they follow.
     */
    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $group = new MachineGroup;
        $this->save($workspace, $group, $data = $this->validated($request, $workspace));
        Activity::record($workspace, 'machine_group.create', $group->name, meta: ['machines' => count($data['machines'])]);

        return back();
    }

    /**
     * Rename a group, change which machines are in it, or the policy they
     * follow.
     */
    #[Audited]
    public function update(Request $request, Workspace $workspace, MachineGroup $group): RedirectResponse
    {
        abort_unless($group->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $before = [
            'name' => $group->name,
            'machines' => array_values($workspace->machines()->where('machine_group_id', $group->id)->orderBy('id')->get(['id'])->map(fn (Machine $machine) => $machine->id)->all()),
            'policy' => $group->machine_policy_id,
        ];
        $data = $this->validated($request, $workspace, $group);
        $this->save($workspace, $group, $data, $before['machines']);

        $machines = $data['machines'];
        sort($machines);
        if ($before['name'] !== $group->name || $before['machines'] !== $machines) {
            Activity::record($workspace, 'machine_group.update', $group->name, meta: array_filter([
                'from' => $before['name'] !== $group->name ? $before['name'] : null,
                'machines' => $before['machines'] !== $machines ? count($machines) : null,
            ], fn ($v) => $v !== null));
        }
        if ($before['policy'] !== $group->machine_policy_id) {
            Activity::record($workspace, 'machine_group.update', $group->name, meta: [
                'policy' => $group->machine_policy_id === null ? __('the default') : $group->policy?->name,
            ]);
        }
        if ($before['name'] === $group->name && $before['machines'] === $machines && $before['policy'] === $group->machine_policy_id) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * Remove the group; its machines go back to the main list, and to the
     * default policy unless they picked their own.
     */
    #[Audited]
    public function destroy(Workspace $workspace, MachineGroup $group): RedirectResponse
    {
        abort_unless($group->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $members = $workspace->machines()->where('machine_group_id', $group->id)->pluck('id');
        MachinePolicies::whileChanging($workspace, $members, fn () => $group->delete());
        Activity::record($workspace, 'machine_group.delete', $group->name);

        return back();
    }

    /**
     * @return array{name: string, machines: list<int>, policy: int|null}
     */
    private function validated(Request $request, Workspace $workspace, ?MachineGroup $group = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'machines' => ['present', 'array'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'policy' => ['sometimes', 'nullable', 'integer', Rule::exists('machine_policies', 'id')->where('workspace_id', $workspace->id)],
        ]);

        return [
            'name' => trim($data['name']),
            'machines' => array_values(array_unique(array_map('intval', $data['machines']))),
            // Left out, it stays as it was.
            'policy' => array_key_exists('policy', $data) ? ($data['policy'] === null ? null : (int) $data['policy']) : $group?->machine_policy_id,
        ];
    }

    /**
     * Save the group and point exactly the chosen machines at it. A machine
     * sits in one group at most, so choosing it here moves it. Machines
     * whose policy changes with it (joining, leaving, or the group's own
     * policy changing) are sent their new one.
     *
     * @param  array{name: string, machines: list<int>, policy: int|null}  $data
     * @param  list<int>  $members  the machines in it before
     */
    private function save(Workspace $workspace, MachineGroup $group, array $data, array $members = []): void
    {
        MachinePolicies::whileChanging($workspace, [...$members, ...$data['machines']], fn () => DB::transaction(function () use ($workspace, $group, $data) {
            $group->forceFill(['workspace_id' => $workspace->id, 'name' => $data['name'], 'machine_policy_id' => $data['policy']])->save();

            $workspace->machines()->where('machine_group_id', $group->id)->whereNotIn('id', $data['machines'])
                ->update(['machine_group_id' => null]);
            $workspace->machines()->whereIn('id', $data['machines'])->update(['machine_group_id' => $group->id]);
        }));
    }
}
