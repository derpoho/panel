<?php

namespace App\Notifications;

use App\Enums\WorkspaceRole;
use App\Models\WorkspaceInvitation;
use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationSent extends Notification
{
    use Queueable;

    /**
     * @param  string  $plainToken  The token for the link; only its hash is stored.
     */
    public function __construct(
        public WorkspaceInvitation $invitation,
        public string $plainToken,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The link to accept, always on the root domain.
     */
    public function acceptUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('invitations.show', $this->plainToken, absolute: false);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $inviter = $this->invitation->inviterName() ?? 'Someone';

        $role = $this->invitation->role;
        $mail = (new MailMessage)
            ->subject("Join {$workspace->name} on ServerOS")
            ->line("{$inviter} invited you to the {$workspace->name} workspace on ServerOS as a {$role->label()}.")
            ->action('Accept invitation', $this->acceptUrl())
            ->line('This invitation expires in '.WorkspaceInvitation::EXPIRES_AFTER_DAYS.' days. If you weren’t expecting it, you can ignore this email.');

        return BrandMail::apply($mail, [
            'image' => 'invite',
            'heading' => "Join {$workspace->name}",
            'paragraphs' => ["{$inviter} invited you to the {$workspace->name} workspace on ServerOS as ".match ($role) {
                WorkspaceRole::Owner => 'an Owner, with full access, billing included.',
                WorkspaceRole::Admin => 'an Admin, with access to everything but billing.',
                WorkspaceRole::Member => 'a Member, so you can work with its machines and services.',
                WorkspaceRole::Viewer => 'a Viewer, so you can see everything without changing it.',
            }],
            'button' => ['label' => 'Accept invitation', 'url' => $this->acceptUrl()],
            'note' => 'The invitation expires in '.WorkspaceInvitation::EXPIRES_AFTER_DAYS.' days. If you weren’t expecting it, ignore this email and nothing changes.',
        ]);
    }
}
