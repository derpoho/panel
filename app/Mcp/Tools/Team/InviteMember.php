<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Invite someone by email, as the Members page does: the same plan rules
 * for roles, the same email with a link to join, and inviting the same
 * address again refreshes the link and role and sends it again.
 */
final class InviteMember extends Tool
{
    /** Invites a person may send a minute, as the Members page allows. */
    private const PER_MINUTE = 20;

    public function name(): string
    {
        return 'invite_member';
    }

    public function title(): string
    {
        return 'Invite someone';
    }

    public function description(): string
    {
        return 'Invites someone to the workspace by email: ServerOS emails them a link to join (it expires after 7 days), and they get the role when they accept, once they’ve signed in or signed up with that address. '
            .'Inviting an address that already has a pending invite refreshes its link and role and emails it again. `role` is admin, member or viewer, or one of the workspace’s own roles on Business (list_roles shows them and what each can do); Member and Viewer are on Pro and Business, so on Essential people can only be invited as Admins. People already in the workspace can’t be invited. '
            .'An admin can change settings and invite or remove people, so check the email address and role with the person first. Returns the pending invite; the link itself is only in the email.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'email' => Schema::string('Their email address.', maxLength: 255),
            'role' => Team::roleSchema(),
        ], ['email', 'role']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMembers;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        // Addresses are kept as typed, trimmed and lowercased.
        if (is_string($arguments['email'] ?? null)) {
            $arguments['email'] = strtolower(trim($arguments['email']));
        }
        $data = $this->validate($arguments, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
        ]);
        $workspace = $context->workspace;
        [$role, $custom] = Team::role($workspace, $data['role']);

        $problems = [];
        if ($role->isStaffRole() && ! $workspace->hasStaffRoles()) {
            $problems[] = 'Member and Viewer roles are on Pro and Business. Invite them as an Admin, or upgrade.';
        }
        if ($workspace->members()->whereRaw('lower(users.email) = ?', [$data['email']])->exists()) {
            $problems[] = 'They’re already in this workspace.';
        }
        if ($problems !== []) {
            throw new ToolError(implode(' ', $problems));
        }

        $key = "mcp:invite:{$workspace->id}:{$context->user->id}";
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new ToolError('That’s a lot of invites in a minute. Wait '.max(1, RateLimiter::availableIn($key)).' seconds and try again.');
        }
        RateLimiter::hit($key, 60);

        $invitation = $workspace->invitations()->firstOrNew(['email' => $data['email']]);
        $resent = $invitation->exists;
        $invitation->role = $role;
        $invitation->custom_role_id = $custom?->id;
        $invitation->sentBy($context->user);
        $plainToken = $invitation->refreshToken();
        $invitation->save();

        $context->record('member.invite', $invitation->email, meta: ['role' => $custom->name ?? $invitation->role->value]);

        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationSent($invitation, $plainToken));

        $invitation->setRelation('inviter', $context->user);
        $label = Team::label($role, $custom);

        return [
            'invitation' => Team::invitationRow($invitation, Team::customRoles($workspace)),
            'resent' => $resent,
            'note' => ($resent ? "Sent the invite to {$invitation->email} again, with a new link" : "Invite sent to {$invitation->email}")
                .". They’ll join as {$label} when they accept; the link expires in ".WorkspaceInvitation::EXPIRES_AFTER_DAYS.' days. cancel_invitation cancels it.',
            'url' => $context->url(Team::PAGE),
        ];
    }
}
