<?php

namespace App\Http\Controllers;

use App\Actions\Machines\MoveMachines;
use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Machines\MovableMachines;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceController extends Controller
{
    /**
     * Create another workspace for the user and take them to it, moving in
     * any machines they picked from their other workspaces.
     */
    #[Audited]
    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace, MoveMachines $moveMachines): Response
    {
        $picked = (array) $request->validated('machines', []);
        $machines = $request->machinesToMove();

        if (count($picked) !== $machines->count()) {
            throw ValidationException::withMessages(['machines' => 'Some of those machines can’t be moved by you. Refresh and pick again.']);
        }
        // A first workspace starts on the trial plan's allowance. Later ones
        // have no trial, so machines moved in wait for a plan there.
        $limit = self::moveLimit($request->user());
        if ($limit !== null && $machines->count() > $limit) {
            throw ValidationException::withMessages(['machines' => "A new workspace can take up to {$limit} machines during its trial. Pick fewer, or move more once it has a plan."]);
        }

        [$workspace, $notes] = DB::transaction(function () use ($request, $createWorkspace, $moveMachines, $machines) {
            $workspace = $createWorkspace->create(
                $request->user(),
                $request->validated('workspace'),
                $request->validated('name'),
            );

            return [$workspace, $machines->isEmpty() ? [] : $moveMachines->move($machines, $workspace)];
        });

        $moved = $machines->count();
        $message = "Created {$workspace->name}".($moved > 0 ? ' and moved in '.($moved === 1 ? '1 machine' : "{$moved} machines") : '');

        // The session is shared across subdomains, so the toast shows on arrival.
        Inertia::flash('toast', ['type' => $notes === [] ? 'success' : 'warning', 'message' => $notes === [] ? $message : $message.'. '.implode(' ', $notes)]);

        return Inertia::location($workspace->url());
    }

    /** Machines the dialog offers to move, grouped by the workspace they're in. */
    public function movable(Request $request): JsonResponse
    {
        return response()->json([
            'trial' => CreateWorkspace::getsTrial($request->user()),
            'limit' => self::moveLimit($request->user()),
            'workspaces' => MovableMachines::for($request->user())
                ->groupBy('workspace_id')
                ->map(fn ($machines) => [
                    'name' => $machines->first()->workspace->name,
                    'machines' => $machines->map(fn (Machine $machine) => [
                        'id' => $machine->id,
                        'name' => $machine->name,
                        'online' => $machine->isOnline(),
                        'services' => (int) $machine->services_count,
                    ])->values(),
                ])->values(),
        ]);
    }

    /** Most machines a new workspace can take in: the trial's allowance, or any number without one. */
    private static function moveLimit(User $user): ?int
    {
        return CreateWorkspace::getsTrial($user) ? Workspace::TRIAL_PLAN->machineLimit() : null;
    }
}
