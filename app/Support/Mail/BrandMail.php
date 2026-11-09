<?php

namespace App\Support\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The designed emails: a dark page with an illustration, the heading beside
 * its icon, then text, an optional table, a button and a note. The lines a
 * notification already has stay on the message (tests and the plain mailer
 * read them); this adds the view that's actually sent.
 *
 * Images live in public/images/email as {key}.jpg (the illustration) and
 * icon-{key}.png (the icon chip): verify, reset, invite, down, up, weekly,
 * subscribe, status. A package can add its own in its public/images/email.
 *
 * @phpstan-type Row array{name: string, value?: string|null, dot?: 'good'|'warn'|'bad'|'neutral'|null, muted?: bool, tone?: 'good'|'warn'|'bad'|'mono'|null}
 * @phpstan-type Section array{title?: string|null, rows: list<Row>, more?: string|null}
 */
final class BrandMail
{
    /**
     * @param  array{image: string, heading: string, paragraphs?: list<string>, sections?: list<Section>, after?: list<string>, button?: array{label: string, url: string}|null, link?: array{label: string, url: string}|null, note?: string|null, signature?: string|null, footer?: list<string>, align?: 'center'|'left'}  $content
     */
    public static function apply(MailMessage $mail, array $content): MailMessage
    {
        // Keep an unsubscribe link added before the view was set.
        return $mail->view(['html' => 'mail.brand', 'text' => 'mail.brand-text'], self::data($content) + array_intersect_key($mail->viewData, ['unsubscribe' => true]));
    }

    /**
     * The view's data, for a Mailable's Content (with: …) as much as for a
     * notification. `brand` replaces the ServerOS logo and name at the top,
     * for emails a customer's own visitors get (a status page's).
     *
     * @param  array{image: string, heading: string, brand?: string|null, paragraphs?: list<string>, sections?: list<Section>, after?: list<string>, button?: array{label: string, url: string}|null, link?: array{label: string, url: string}|null, note?: string|null, signature?: string|null, footer?: list<string>, align?: 'center'|'left', unsubscribe?: array{url: string, label: string}}  $content
     * @return array<string, mixed>
     */
    public static function data(array $content): array
    {
        $data = $content + [
            'brand' => null,
            'paragraphs' => [],
            'sections' => [],
            'after' => [],
            'button' => null,
            // A smaller text link under the button.
            'link' => null,
            'note' => null,
            'signature' => null,
            'footer' => [],
            // Body text is centred; a letter (a support reply) reads left.
            'align' => 'center',
        ];
        $data['imageUrl'] = asset('images/email/'.$content['image'].'.jpg');
        $data['iconUrl'] = asset('images/email/icon-'.$content['image'].'.png');
        $data['logoUrl'] = asset('images/email/logo.png');

        return $data;
    }
}
