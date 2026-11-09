<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a workspace's owner and admins: someone signed in with Google on one
 * of its verified domains and asked to join.
 */
class WorkspaceJoinRequested extends Notification
{
    use Queueable;

    public function __construct(
        public Workspace $workspace,
        public User $requester,
        public string $domain,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function membersUrl(): string
    {
        return route('workspace.members.index', ['workspace' => $this->workspace->slug]).'#join-requests';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->requester->name;
        $line = "{$name} ({$this->requester->email}) signed in with their {$this->domain} Google account and asked to join {$this->workspace->name}.";

        $mail = (new MailMessage)
            ->subject("{$name} asked to join {$this->workspace->name}")
            ->line($line)
            ->action('Review the request', $this->membersUrl());

        return BrandMail::apply($mail, [
            'image' => 'invite',
            'heading' => "{$name} asked to join",
            'paragraphs' => [$line, 'Let them in with a role, or turn the request down, from Members.'],
            'button' => ['label' => 'Review the request', 'url' => $this->membersUrl()],
            'note' => 'You get this because you manage members of '.$this->workspace->name.'.',
        ]);
    }
}
