<?php

namespace App\Support\Mail;

use App\Events\EmailUnsubscribed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

/**
 * Who asked us to stop emailing them, by address, so a link works for
 * anyone we sent to (a test copy, a package's own lists, anything later).
 */
class Unsubscribes
{
    /**
     * What a link can stop: one workspace's alert emails or weekly summary
     * (that person's own setting, as in Settings), emails about feedback
     * board ideas they voted for, one workspace's trial-ending emails, or,
     * with no list, every email that isn't about their account.
     */
    public const LISTS = ['alerts', 'summary', 'feedback', 'trial'];

    public static function url(string $email, ?string $list = null, ?int $workspace = null): string
    {
        return URL::signedRoute('unsubscribe', array_filter([
            'email' => self::normalise($email),
            'list' => $list,
            'workspace' => $workspace,
        ], fn ($value) => $value !== null));
    }

    /**
     * Put the link where people and mail clients look for it: the
     * List-Unsubscribe headers (one-click, as Gmail and Yahoo expect) and a
     * line at the bottom of the email.
     */
    public static function attach(MailMessage $mail, string $url, string $label = 'Unsubscribe'): MailMessage
    {
        $mail->withSymfonyMessage(function (Email $message) use ($url): void {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });

        $salutation = filled($mail->salutation) ? $mail->salutation : 'Regards,'."\n".config('app.name');
        // The designed emails (BrandMail) put it in their footer.
        $mail->viewData['unsubscribe'] = ['url' => $url, 'label' => $label];

        return $mail->salutation($salutation."\n\n[{$label}]({$url})");
    }

    public static function has(string $email): bool
    {
        return DB::table('email_unsubscribes')->where('email', self::normalise($email))->exists();
    }

    public static function add(string $email): void
    {
        $email = self::normalise($email);
        DB::table('email_unsubscribes')->insertOrIgnore(['email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        event(new EmailUnsubscribed($email));
    }

    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }
}
