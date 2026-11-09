<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceJoinRequest;
use App\Support\Audit\Audited;
use App\Support\Workspaces\RoleChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People who signed in with Google on one of the workspace's domains and
 * asked to join: let them in with a role, or turn them down. Also the
 * page that tells the person they asked.
 */
class WorkspaceJoinRequestController extends Controller
{
    /** Let them in, like accepting an invite. */
    #[Audited]
    public function approve(Request $request, Workspace $workspace, WorkspaceJoinRequest $joinRequest): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($joinRequest->workspace_id === $workspace->id && $joinRequest->state === WorkspaceJoinRequest::PENDING, 404);

        $choice = RoleChoice::parse($workspace, $request->input('role'));
        if (is_string($choice)) {
            throw ValidationException::withMessages(['role' => $choice]);
        }

        $user = $joinRequest->user;
        DB::transaction(function () use ($workspace, $joinRequest, $user, $choice, $request): void {
            if (! $workspace->hasMember($user)) {
                $workspace->members()->attach($user, ['role' => $choice->role->value, 'custom_role_id' => $choice->custom?->id]);
            }
            $joinRequest->decide(WorkspaceJoinRequest::APPROVED, $request->user());
        });

        Activity::record($workspace, 'member.request_approve', $user->name, meta: [
            'role' => $choice->label(),
            'via' => 'google_domain_request',
            'domain' => $joinRequest->domain,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} is in, as {$choice->label()}."]);

        return back();
    }

    /** Turn the request down. They can still be invited. */
    #[Audited]
    public function deny(Request $request, Workspace $workspace, WorkspaceJoinRequest $joinRequest): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($joinRequest->workspace_id === $workspace->id && $joinRequest->state === WorkspaceJoinRequest::PENDING, 404);

        $joinRequest->decide(WorkspaceJoinRequest::DENIED, $request->user());
        Activity::record($workspace, 'member.request_deny', $joinRequest->user->name, meta: ['domain' => $joinRequest->domain]);

        return back();
    }

    /** "Asked to join Acme": where someone lands after asking. */
    public function show(Request $request): Response
    {
        $requests = WorkspaceJoinRequest::query()
            ->where('user_id', $request->user()->id)
            ->pending()
            ->with('workspace:id,name')
            ->latest()
            ->get();

        return Inertia::render('auth/JoinRequested', [
            'workspaces' => $requests->map(fn (WorkspaceJoinRequest $r) => $r->workspace->name)->unique()->values()->all(),
        ]);
    }
}
