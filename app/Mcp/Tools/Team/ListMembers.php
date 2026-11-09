<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\User;
use App\Models\WorkspaceInvitation;

/**
 * Who's in the workspace and who's been invited, as the Members page
 * lists them.
 */
final class ListMembers extends Tool
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    public function name(): string
    {
        return 'list_members';
    }

    public function title(): string
    {
        return 'List members and invites';
    }

    public function description(): string
    {
        return 'Lists the people in the workspace as the Members page does: owner first, then admins, members and viewers, each by name. For each: id, name, email, `role` (owner, admin, member or viewer: the base role) and `roleLabel` (what the page shows, which is the custom role’s name for someone given one of the workspace’s own roles on Business), when they joined, `twoFactor` (an authenticator app or a passkey is set up) and `needsTwoFactor` (the workspace requires two-factor sign-in and they haven’t set it up, so they’re locked out until they do), whether it’s you, and whether you may change their role or remove them. '
            .'Also the pending invites (email, role, who sent it, when it expires and whether it already has). Use `search` to filter by name or email. To change someone’s role use change_member_role; to remove someone, remove_member; to invite, invite_member; list_roles explains the roles.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'search' => Schema::string('Only people whose name or email contains this (any case).', maxLength: 255),
            'limit' => Schema::integer('Most members to return (default '.self::DEFAULT_LIMIT.').', 1, self::MAX_LIMIT),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);
        $workspace = $context->workspace;
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        $customRoles = Team::customRoles($workspace);
        $canManage = $context->can(WorkspacePermission::ManageMembers);

        $query = Team::members($workspace);
        Team::search($query, $data['search'] ?? null);
        $total = (clone $query)->count();
        $members = $query->limit($limit)->get()->map(fn (User $member) => Team::memberRow($member, $context, $customRoles, $canManage))->all();

        $invites = $workspace->invitations()->with('inviter:id,name')->latest()->latest('id');
        if (($search = trim((string) ($data['search'] ?? ''))) !== '') {
            $invites->whereRaw('lower(email) like ?', ['%'.mb_strtolower($search).'%']);
        }
        $invitations = $invites->limit(self::MAX_LIMIT)->get()->map(fn (WorkspaceInvitation $i) => Team::invitationRow($i, $customRoles))->all();

        return [
            'workspace' => $workspace->name,
            'members' => $members,
            'total' => $total,
            'truncated' => $total > count($members),
            'invitations' => $invitations,
            'requireTwoFactor' => $workspace->require_two_factor,
            'withoutTwoFactor' => $workspace->members()->whereNull('users.two_factor_confirmed_at')->doesntHave('passkeys')->count(),
            'canManageMembers' => $canManage,
            'url' => $context->url(Team::PAGE),
        ];
    }
}
