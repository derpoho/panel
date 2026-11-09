<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Revoke a pending invitation, as Revoke on the Members page does: its
 * link stops working.
 */
final class CancelInvitation extends Tool
{
    public function name(): string
    {
        return 'cancel_invitation';
    }

    public function title(): string
    {
        return 'Cancel an invite';
    }

    public function description(): string
    {
        return 'Cancels a pending invite (Revoke on the Members page): the link in the email stops working. Say which by its id or the email address it was sent to (list_members shows pending invites). To undo, invite them again with invite_member. People who already joined are removed with remove_member instead.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'invitation' => Schema::string('The invite: its id, or the email address it was sent to.', maxLength: 255),
        ], ['invitation']);
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
            'invitation' => ['required', Team::reference()],
        ]);
        $invitation = Team::invitation($context, $data['invitation']);

        $invitation->delete();
        $context->record('member.uninvite', $invitation->email);

        return [
            'cancelled' => ['id' => $invitation->id, 'email' => $invitation->email],
            'note' => "Cancelled the invite for {$invitation->email}. Its link no longer works.",
        ];
    }
}
