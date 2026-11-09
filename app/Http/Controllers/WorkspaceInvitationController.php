<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteMemberRequest;
use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class WorkspaceInvitationController extends Controller
{
    /**
     * Invite someone by email. Inviting the same address again refreshes the
     * link and role and sends it again.
     */
    #[Audited]
    public function store(InviteMemberRequest $request, Workspace $workspace): RedirectResponse
    {
        $invitation = $workspace->invitations()->firstOrNew(['email' => $request->validated('email')]);
        $invitation->role = $request->chosenRole();
        $invitation->custom_role_id = $request->chosenCustomRole()?->id;
        $invitation->sentBy($request->user());
        $plainToken = $invitation->refreshToken();
        $invitation->save();

        Activity::record($workspace, 'member.invite', $invitation->email, meta: ['role' => $request->chosenCustomRole()->name ?? $invitation->role->value]);

        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationSent($invitation, $plainToken));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Invite sent to {$invitation->email}."]);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, WorkspaceInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $invitation->delete();
        Activity::record($workspace, 'member.uninvite', $invitation->email);

        return back();
    }
}
