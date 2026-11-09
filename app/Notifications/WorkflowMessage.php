<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A workflow's alert to someone outside the workspace, like an agency's
 * client: what happened, from the workspace by name, with replies going
 * to its owner. No links into the panel, since they can't sign in.
 */
class WorkflowMessage extends Notification
{
    use Queueable;

    public function __construct(
        public string $subject,
        public string $message,
        public string $from,
        public ?string $replyTo = null,
        public bool $bad = true,
        /** "Sent with ServerOS", through the owner's partner link if they have one. */
        public ?string $poweredBy = null,
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
        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo, $this->from);
        }
        foreach (preg_split('/\R{2,}/', trim($this->message)) ?: [] as $paragraph) {
            if ($paragraph !== '') {
                $mail->line($paragraph);
            }
        }

        $mail->salutation("— {$this->from}");
        if ($this->poweredBy !== null) {
            $mail->line("Sent with [ServerOS]({$this->poweredBy})");
        }

        return $mail;
    }
}
