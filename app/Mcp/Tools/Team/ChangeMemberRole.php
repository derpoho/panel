<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;

/**
 * Give a member another role, as the role picker on the Members page
 * does: never the owner's, never your own, and only the roles the plan
 * offers.
 */
final class ChangeMemberRole extends Tool
{
    public function name(): string
    {
        return 'change_member_role';
    }

    public function title(): string
    {
        return 'Change a member’s role';
    }

    public function description(): string
    {
        return 'Changes what someone in the workspace may do, by giving them another role: admin, member or viewer, or one of the workspace’s own roles on Business (list_roles shows them and what each can do). The owner’s role can’t be changed, nobody can change their own, and Member and Viewer are on Pro and Business only. '
            .'Making someone an Admin lets them change settings and invite or remove people, so check with the person before granting more access. Takes effect at once, including for that member’s own MCP connections. Returns the member as list_members shows them.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'member' => Team::memberSchema(),
            'role' => Team::roleSchema(),
        ], ['member', 'role']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMembers;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'member' => ['required', Team::reference()],
            'role' => ['required', 'string', 'max:60'],
        ]);
        $workspace = $context->workspace;
        $member = Team::member($context, $data['member']);
        [$role, $custom] = Team::role($workspace, $data['role']);

        $current = $workspace->roleOf($member);
        if ($current === WorkspaceRole::Owner) {
            throw new ToolError('The owner’s role can’t be changed.');
        }
        if ($member->is($context->user)) {
            throw new ToolError('You can’t change your own role.');
        }
        if ($role->isStaffRole() && ! $workspace->hasStaffRoles()) {
            throw new ToolError('Member and Viewer roles are on Pro and Business.');
        }

        $customRoles = Team::customRoles($workspace);
        $before = Team::memberRow($member, $context, $customRoles, true);
        $label = Team::label($role, $custom);

        $unchanged = $current === $role && (int) $member->pivot->custom_role_id === (int) $custom?->id;
        if (! $unchanged) {
            $workspace->members()->updateExistingPivot($member->id, [
                'role' => $role->value,
                'custom_role_id' => $custom?->id,
            ]);
            $context->record('member.role', $member->name, meta: ['role' => $label]);
        } else {
            AuditContext::nothingChanged();
        }

        return [
            'member' => Team::memberRow(Team::member($context, $member->id), $context, $customRoles, true),
            'previousRole' => $before['roleLabel'],
            'changed' => ! $unchanged,
            'note' => $unchanged ? "{$member->name} already has the {$label} role." : "{$member->name} now has the {$label} role.",
        ];
    }
}
