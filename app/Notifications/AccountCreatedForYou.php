<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Sent when an admin creates someone's account: a link to choose their own
 * password, so nobody else ever knows it.
 */
class AccountCreatedForYou extends Notification
{
    use Queueable;

    public function __construct(public Workspace $workspace) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false);

        return (new MailMessage)
            ->subject('Your ServerOS account is ready')
            ->greeting("Hi {$notifiable->name},")
            ->line("You’re in early. We’ve set up a ServerOS account for you, with the {$this->workspace->name} workspace and a 14-day Pro trial.")
            ->action('Choose your password', $url)
            ->line('The link works for '.config('auth.passwords.users.expire', 60).' minutes. After that, use “Forgot your password?” on the sign-in page to get a new one.');
    }
}
