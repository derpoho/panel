<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Take someone out of the workspace, as Remove on the Members page does.
 * The owner can't be removed; removing yourself is leaving.
 */
final class RemoveMember extends Tool
{
    public function name(): string
    {
        return 'remove_member';
    }

    public function title(): string
    {
        return 'Remove a member';
    }

    public function description(): string
    {
        return 'Removes someone from the workspace: they lose access at once, and their API keys and MCP connections for it stop working. Nothing they did is undone, and their account stays (they may be in other workspaces). The owner can’t be removed. If it’s you, you leave the workspace and this connection stops working. '
            .'To bring them back, invite them again with invite_member. Ask the person first, then pass confirm set to the member’s email address exactly (list_members shows it).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'member' => Team::memberSchema(),
            'confirm' => Schema::confirm('the member’s email address'),
        ], ['member', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMembers;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'member' => ['required', Team::reference()],
            'confirm' => ['nullable', 'string', 'max:255'],
        ]);
        $workspace = $context->workspace;
        $member = Team::member($context, $data['member']);
        $leaving = $member->is($context->user);

        if ($workspace->roleOf($member) === WorkspaceRole::Owner) {
            throw new ToolError($leaving ? 'Owners can’t leave their own workspace.' : 'The owner can’t be removed.');
        }

        $given = trim((string) ($data['confirm'] ?? ''));
        if ($given === '') {
            throw new ToolError("This can’t be undone. Check with the person first, then call again with confirm set to the member’s email address exactly: “{$member->email}”.");
        }
        if (mb_strtolower($given) !== mb_strtolower($member->email)) {
            throw new ToolError("confirm doesn’t match. It must be the member’s email address exactly: “{$member->email}”. Nothing was changed.");
        }

        $workspace->members()->detach($member->id);
        $context->record($leaving ? 'member.leave' : 'member.remove', $member->name);

        return [
            'removed' => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email],
            'left' => $leaving,
            'note' => $leaving
                ? "You’ve left {$workspace->name}. This connection no longer works; someone who manages members has to invite you again for you to come back."
                : "{$member->name} was removed from {$workspace->name}. invite_member invites them again.",
        ];
    }
}
