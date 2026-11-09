<?php

namespace App\Notifications;

use App\Support\Mail\BrandMail;
use App\Support\Mail\Unsubscribes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something in a workspace needs someone: a monitor went down or came back,
 * a certificate is about to expire, a server stopped reporting or is filling up.
 */
class MonitorAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public string $url,
        public bool $bad,
        public string $actionLabel = 'Open the monitor',
        // For the unsubscribe link: this workspace's alerts, for this person.
        public ?int $workspaceId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting($this->subject);

        if ($this->bad) {
            $mail->error();
        }
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        $mail->action($this->actionLabel, $this->url);
        // Every kind of alert shares it: red when something's wrong, green
        // when it's fixed, its lines (what, then why) as the text.
        $mail = BrandMail::apply($mail, [
            'image' => $this->bad ? 'down' : 'up',
            'heading' => $this->subject,
            'paragraphs' => array_values(array_filter($this->lines, fn ($line) => filled($line))),
            'button' => ['label' => $this->actionLabel, 'url' => $this->url],
        ]);

        $email = $notifiable->email ?? null;

        return $this->workspaceId !== null && is_string($email)
            ? Unsubscribes::attach($mail, Unsubscribes::url($email, 'alerts', $this->workspaceId), 'Stop alert emails from this workspace')
            : $mail;
    }
}
