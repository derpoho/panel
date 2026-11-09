<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent from admin › System to check that email works. */
class TestEmail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'ServerOS test email');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p style="font-family:sans-serif;font-size:14px">If you can read this, ServerOS email works. Sent through <strong>'.e((string) config('mail.default')).'</strong>.</p>');
    }
}
