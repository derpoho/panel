<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Models\WorkspaceInvitation;
use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * What the team tools share: finding a member or an invitation the way the
 * Members page names them, reading a role the way its role picker does
 * (a built-in role or one of the workspace's own), and how members,
 * invitations and roles look to an agent.
 */
final class Team
{
    /** The Members page, on the workspace's own address. */
    public const PAGE = '/settings/members';

    /** How the alert email choices read on the Profile tab. */
    public const ALERT_EMAILS = ['all' => 'Everything', 'problems' => 'Only problems', 'none' => 'None'];

    /**
     * The workspace's members as the Members page orders them: owner, admins,
     * members, viewers, then by name.
     *
     * @return BelongsToMany<User, Workspace, Membership>
     */
    public static function members(Workspace $workspace): BelongsToMany
    {
        return $workspace->members()
            ->withCount('passkeys')
            ->orderByRaw('case user_workspace.role when ? then 0 when ? then 1 when ? then 2 else 3 end', ['owner', 'admin', 'member'])
            ->orderBy('users.name');
    }

    /**
     * A member, by user id, email address (any case) or name when only one
     * person has it.
     */
    public static function member(McpContext $context, mixed $reference): User
    {
        $reference = is_int($reference) ? (string) $reference : trim((string) (is_string($reference) ? $reference : ''));
        if ($reference === '') {
            throw new ToolError('Say which member: their email address or id (list_members shows them).');
        }

        $workspace = $context->workspace;
        $query = self::members($workspace);

        if (ctype_digit($reference) && ($member = (clone $query)->whereKey((int) $reference)->first()) !== null) {
            return $member;
        }
        if (str_contains($reference, '@') && ($member = (clone $query)->whereRaw('lower(users.email) = ?', [mb_strtolower($reference)])->first()) !== null) {
            return $member;
        }

        $named = (clone $query)->whereRaw('lower(users.name) = ?', [mb_strtolower($reference)])->get();
        if ($named->count() === 1) {
            return $named->first();
        }
        if ($named->count() > 1) {
            throw new ToolError("Several members are called “{$reference}”: ".$named->map(fn (User $u) => $u->email)->implode(', ').'. Use their email address.');
        }

        if (str_contains($reference, '@') && $workspace->invitations()->whereRaw('lower(email) = ?', [mb_strtolower($reference)])->exists()) {
            throw new ToolError("{$reference} has been invited to {$workspace->name} but hasn’t joined yet. cancel_invitation cancels the invite.");
        }

        throw new ToolError("No member “{$reference}” in {$workspace->name}. list_members shows who’s in it.");
    }

    /**
     * A pending invitation, by its id or the email address it was sent to.
     */
    public static function invitation(McpContext $context, mixed $reference): WorkspaceInvitation
    {
        $reference = is_int($reference) ? (string) $reference : trim((string) (is_string($reference) ? $reference : ''));
        if ($reference === '') {
            throw new ToolError('Say which invite: its id or the email address it was sent to (list_members shows pending invites).');
        }

        $workspace = $context->workspace;
        $query = $workspace->invitations();

        if (ctype_digit($reference) && ($invitation = (clone $query)->find((int) $reference)) !== null) {
            return $invitation;
        }
        if (($invitation = (clone $query)->whereRaw('lower(email) = ?', [mb_strtolower($reference)])->first()) !== null) {
            return $invitation;
        }

        if (str_contains($reference, '@') && $workspace->members()->whereRaw('lower(users.email) = ?', [mb_strtolower($reference)])->exists()) {
            throw new ToolError("{$reference} has already joined {$workspace->name}, so there’s no invite to cancel. remove_member removes people.");
        }

        throw new ToolError("No pending invite for “{$reference}” in {$workspace->name}. list_members shows pending invites.");
    }

    /**
     * The role asked for, as the role picker offers them: admin, member or
     * viewer, or one of the workspace's own roles by name or as custom:<id>.
     * A custom role sits on top of Viewer, so a workspace that leaves
     * Business falls back to read-only access. Checks what the plan allows
     * for custom roles; staff roles are checked by each tool, whose wording
     * differs.
     *
     * @return array{0: WorkspaceRole, 1: WorkspaceCustomRole|null}
     */
    public static function role(Workspace $workspace, string $value): array
    {
        $value = trim($value);
        $lower = mb_strtolower($value);

        foreach (WorkspaceRole::assignable() as $role) {
            if ($lower === $role->value) {
                return [$role, null];
            }
        }
        if ($lower === WorkspaceRole::Owner->value) {
            throw new ToolError('The Owner role can’t be given: a workspace has one owner. Pick admin, member or viewer, or one of the workspace’s own roles (list_roles shows them).');
        }

        $custom = null;
        if (preg_match('/^custom:(\d+)$/', $lower, $m) === 1) {
            if (! $workspace->hasCustomRoles()) {
                throw new ToolError('Custom roles are on Business.');
            }
            $custom = $workspace->customRoles()->find((int) $m[1]);
            if ($custom === null) {
                throw new ToolError('That role no longer exists. list_roles shows the roles there are.');
            }
        } else {
            $custom = $workspace->customRoles()->whereRaw('lower(name) = ?', [$lower])->first();
            if ($custom === null) {
                throw new ToolError('Pick a role: admin, member or viewer, or one of the workspace’s own roles (list_roles shows them).');
            }
            if (! $workspace->hasCustomRoles()) {
                throw new ToolError('Custom roles are on Business.');
            }
        }

        return [WorkspaceRole::Viewer, $custom];
    }

    /** What a role is called: the custom role's name, or the built-in one's. */
    public static function label(WorkspaceRole $role, ?WorkspaceCustomRole $custom): string
    {
        return $custom->name ?? $role->label();
    }

    /**
     * The workspace's own roles by id, while the plan lets them count: off
     * Business, everyone is shown (and works) as their base role.
     *
     * @return Collection<int, WorkspaceCustomRole>
     */
    public static function customRoles(Workspace $workspace): Collection
    {
        return $workspace->hasCustomRoles() ? $workspace->customRoles()->get()->keyBy('id') : collect();
    }

    /**
     * A member as the Members page shows them, and what the person may do
     * to them through MCP.
     *
     * @param  Collection<int, WorkspaceCustomRole>  $customRoles  from customRoles()
     * @param  bool  $canManage  whether the person may manage members
     * @return array<string, mixed>
     */
    public static function memberRow(User $member, McpContext $context, Collection $customRoles, bool $canManage): array
    {
        /** @var Pivot $membership */
        $membership = $member->getRelation('pivot');
        $role = WorkspaceRole::tryFrom((string) $membership->getAttribute('role')) ?? WorkspaceRole::Viewer;
        $custom = $customRoles->get($membership->getAttribute('custom_role_id'));
        $twoFactor = $member->two_factor_confirmed_at !== null || ($member->passkeys_count ?? $member->passkeys()->count()) > 0;
        $isYou = $member->is($context->user);

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $role->value,
            'roleLabel' => self::label($role, $custom),
            'customRole' => $custom === null ? null : ['id' => $custom->id, 'name' => $custom->name],
            'twoFactor' => $twoFactor,
            'needsTwoFactor' => $context->workspace->require_two_factor && ! $twoFactor,
            'joinedAt' => $membership->getAttribute('created_at')?->toIso8601String(),
            'isYou' => $isYou,
            'canChangeRole' => $canManage && $role !== WorkspaceRole::Owner && ! $isYou,
            'canRemove' => $canManage && $role !== WorkspaceRole::Owner,
        ];
    }

    /**
     * A pending invitation as the Members page shows it.
     *
     * @param  Collection<int, WorkspaceCustomRole>  $customRoles  from customRoles()
     * @return array<string, mixed>
     */
    public static function invitationRow(WorkspaceInvitation $invitation, Collection $customRoles): array
    {
        $custom = $customRoles->get($invitation->custom_role_id);

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'roleLabel' => self::label($invitation->role, $custom),
            'customRole' => $custom === null ? null : ['id' => $custom->id, 'name' => $custom->name],
            'invitedBy' => $invitation->inviterName(),
            'sentAt' => ($invitation->updated_at ?? $invitation->created_at)?->toIso8601String(),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'expired' => $invitation->isExpired(),
        ];
    }

    /**
     * What the acting person is emailed from this workspace, as the Profile
     * tab shows it.
     *
     * @return array<string, mixed>
     */
    public static function notifications(McpContext $context): array
    {
        $workspace = $context->workspace;
        $membership = $workspace->members()->withPivot(['alert_emails', 'weekly_summary'])->whereKey($context->user->id)->first()?->pivot;
        $alertEmails = (string) ($membership->alert_emails ?? 'all');
        // Only owners and admins are sent alerts.
        $getsAlerts = in_array($membership->role ?? null, [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value], true);
        $workspaceEmails = (bool) ($workspace->alert_email ?? true);

        return [
            'alertEmails' => $alertEmails,
            'alertEmailsLabel' => self::ALERT_EMAILS[$alertEmails] ?? $alertEmails,
            'weeklySummary' => (bool) ($membership->weekly_summary ?? true),
            'getsAlerts' => $getsAlerts,
            'workspaceSendsAlertEmails' => $workspaceEmails,
            'notes' => array_values(array_filter([
                $getsAlerts
                    ? '“Only problems” skips the “it’s back” and “good news” emails. The workspace webhook still gets everything.'
                    : 'Alerts go to owners and admins; ask one to change your role if you need them.',
                $getsAlerts && ! $workspaceEmails ? 'Alert emails are turned off for the whole workspace, so none are sent whatever is chosen here.' : null,
                'The weekly summary arrives on Monday morning: uptime, incidents, deploys, backups, and what needs attention.',
            ])),
            'url' => $context->url('/settings/profile'),
        ];
    }

    /** A rule for references to members and invites: an id, an email or a name. */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an email address or an id.");
            }
        };
    }

    /** @return array<string, mixed> */
    public static function memberSchema(string $description = 'The member: their email address or user id (as list_members shows). A name works when only one member has it.'): array
    {
        return Schema::string($description, maxLength: 255);
    }

    /** @return array<string, mixed> */
    public static function roleSchema(): array
    {
        return Schema::string('The role: admin, member or viewer, or one of the workspace’s own roles (Business) by its name or as custom:<id>. list_roles shows them and what each can do. The owner role can’t be given.', maxLength: 60);
    }

    /**
     * Only members whose name or email contains the text, in any case.
     *
     * @param  BelongsToMany<User, Workspace, Membership>  $query
     */
    public static function search(BelongsToMany $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn ($q) => $q->whereRaw('lower(users.name) like ?', [$like])->orWhereRaw('lower(users.email) like ?', [$like]));
    }
}
