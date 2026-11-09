<?php

namespace App\Http\Controllers;

use App\Actions\Machines\CreateJoinCommand;
use App\Models\DaemonEnrolment;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

class DaemonConnectController extends Controller
{
    /**
     * What the Connect machine dialog offers under Advanced: the policies a
     * new machine can follow, with what each sends, and the groups it can
     * join (with the policy each group gives its machines).
     */
    public function options(Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $default = MachinePolicies::defaultFor($workspace);
        $policies = $workspace->machinePolicies()->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json([
            'defaultPolicyId' => $default->id,
            'policies' => $policies->map(fn (MachinePolicy $policy) => $policy->toPageProps())->values(),
            'groups' => $workspace->machineGroups()->orderBy('name')->get()
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name, 'policyId' => $group->machine_policy_id])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    #[NotAudited('Only makes a join command; the machine joining is recorded when it enrols, credited to whoever made the command')]
    public function store(Request $request, Workspace $workspace, CreateJoinCommand $join): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'replace' => ['sometimes', 'boolean'],
            'policy' => ['nullable', 'integer', Rule::exists('machine_policies', 'id')->where('workspace_id', $workspace->id)],
            'group' => ['nullable', 'integer', Rule::exists('machine_groups', 'id')->where('workspace_id', $workspace->id)],
        ]);

        try {
            $created = $join->create(
                $workspace,
                $request->user(),
                replace: (bool) ($data['replace'] ?? false),
                policy: isset($data['policy']) ? $workspace->machinePolicies()->find($request->integer('policy')) : null,
                group: isset($data['group']) ? $workspace->machineGroups()->find($request->integer('group')) : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        if ($created === null) {
            return response()->json(['error' => 'This workspace has reached its machine limit.'], 409);
        }

        return response()->json([
            'command' => $created['command'],
            'expiresAt' => $created['enrolment']->expires_at->toIso8601String(),
            'enrolmentId' => $created['enrolment']->id,
        ])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, Workspace $workspace, DaemonEnrolment $enrolment): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($enrolment->workspace_id === $workspace->id, 404);

        $machine = $enrolment->machine_id === null ? null : $workspace->machines()->find($enrolment->machine_id);
        // A machine whose policy has discovery off is done once it's
        // connected: no scan is coming.
        $discovery = $machine === null ? true : MachinePolicies::for($machine)->discovery;

        return response()->json([
            'state' => self::state($enrolment, $machine, $discovery),
            'machineId' => $machine?->id,
            'machineName' => $machine?->name,
            'services' => $machine === null ? 0 : $machine->services_count,
            'listeners' => $machine === null ? 0 : count($machine->inventory['listeners'] ?? []),
            'discovery' => $discovery,
        ])->header('Cache-Control', 'no-store');
    }

    /** waiting → enrolled → connected → discovered, or expired. */
    public static function state(DaemonEnrolment $enrolment, ?Machine $machine, bool $discovery = true): string
    {
        return match (true) {
            $machine === null => $enrolment->expires_at->isPast() ? 'expired' : 'waiting',
            $machine->last_seen_at === null => 'enrolled',
            $machine->inventory === null && $discovery => 'connected',
            default => 'discovered',
        };
    }
}
