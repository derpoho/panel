<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceJoinRequest;
use App\Support\Audit\Audited;
use App\Support\Extensions\Extensions;
use App\Support\Workspaces\RoleChoice;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $customRoles = $workspace->hasCustomRoles();
        $extensions = app(Extensions::class);

        $members = $workspace->members()
            ->withCount('passkeys')
            ->orderByRaw('case user_workspace.role when ? then 0 when ? then 1 when ? then 2 else 3 end', ['owner', 'admin', 'member'])
            ->orderBy('name')
            ->get();
        // What packages add to each member, like "managed by SCIM".
        $added = $extensions->memberPropsFor($workspace, array_values($members->map(fn (User $member) => $member->id)->all()));

        $members = $members
            ->map(function (User $member) use ($request, $customRoles, $added): array {
                /** @var Pivot $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar,
                    'role' => $membership->getAttribute('role'),
                    'customRoleId' => $customRoles ? $membership->getAttribute('custom_role_id') : null,
                    'twoFactor' => $member->two_factor_confirmed_at !== null || $member->passkeys_count > 0,
                    'joinedAt' => $membership->getAttribute('created_at')?->toIso8601String(),
                    'isYou' => $member->is($request->user()),
                    ...($added[$member->id] ?? []),
                ];
            });

        $invitations = $workspace->invitations()
            ->with('inviter:id,name')
            ->latest()
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'customRoleId' => $customRoles ? $invitation->custom_role_id : null,
                'invitedBy' => $invitation->inviterName(),
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->isExpired(),
            ]);

        $canManage = $request->user()->can('manageMembers', $workspace);
        // People who signed in with Google on a verified domain and asked to join.
        $joinRequests = $canManage ? $workspace->joinRequests()
            ->pending()
            ->with('user:id,name,email,avatar_path')
            ->oldest()
            ->get()
            ->map(fn (WorkspaceJoinRequest $joinRequest) => [
                'id' => $joinRequest->id,
                'name' => $joinRequest->user->name,
                'email' => $joinRequest->user->email,
                'avatar' => $joinRequest->user->avatar,
                'domain' => $joinRequest->domain,
                'askedAt' => $joinRequest->created_at?->toIso8601String(),
            ]) : [];

        return Inertia::render('workspace/settings/Members', [
            'workspace' => $workspace->toPageProps(),
            'members' => $members,
            'invitations' => $invitations,
            'roles' => array_map(fn (WorkspaceRole $role) => $role->toPageProps(), WorkspaceRole::cases()),
            'canManageMembers' => $canManage,
            'joinRequests' => $joinRequests,
            'joinRole' => RoleChoice::value($workspace->domain_join_role, $customRoles ? $workspace->domain_join_custom_role_id : null),
            'hasStaffRoles' => $workspace->hasStaffRoles(),
            'hasCustomRoles' => $customRoles,
            'customRoles' => $workspace->customRoles->map->toPageProps(),
            'permissions' => array_map(fn (WorkspacePermission $p) => ['value' => $p->value, 'label' => $p->label()], WorkspacePermission::cases()),
            'requireTwoFactor' => $workspace->require_two_factor,
            ...$extensions->pagePropsFor('workspace/settings/Members', $request, $workspace),
        ]);
    }

    #[Audited]
    public function update(UpdateMemberRoleRequest $request, Workspace $workspace, User $member): HttpResponse
    {
        abort_unless($workspace->hasMember($member), 404);

        $custom = $request->chosenCustomRole();
        $workspace->members()->updateExistingPivot($member->id, [
            'role' => $request->chosenRole()->value,
            'custom_role_id' => $custom?->id,
        ]);
        $label = $custom->name ?? $request->chosenRole()->label();
        Activity::record($workspace, 'member.role', $member->name, meta: ['role' => $label]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} now has the {$label} role."]);

        return back();
    }

    /**
     * Remove a member, or let a member leave.
     */
    #[Audited]
    public function destroy(Request $request, Workspace $workspace, User $member): HttpResponse
    {
        abort_unless($workspace->hasMember($member), 404);

        $leaving = $member->is($request->user());

        if (! $leaving) {
            Gate::authorize('manageMembers', $workspace);
        }

        if ($workspace->roleOf($member) === WorkspaceRole::Owner) {
            throw ValidationException::withMessages([
                'member' => $leaving ? 'Owners can’t leave their own workspace.' : 'The owner can’t be removed.',
            ]);
        }

        $workspace->members()->detach($member->id);
        // Signing in with Google on the company's domain doesn't bring them straight back.
        WorkspaceJoinRequest::blockReturn($workspace, $member, $request->user());
        Activity::record($workspace, $leaving ? 'member.leave' : 'member.remove', $member->name);

        if ($leaving) {
            return Inertia::location(rtrim((string) config('app.url'), '/').route('dashboard', absolute: false));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} was removed."]);

        return back();
    }
}
