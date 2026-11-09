<?php

namespace App\Mail;

use App\Models\StatusSubscriber;
use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Asks someone who subscribed to a status page to confirm it was them. */
class StatusSubscribeConfirm extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StatusSubscriber $subscriber, public string $pageTitle) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirm updates from {$this->pageTitle}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.brand',
            text: 'mail.brand-text',
            with: BrandMail::data([
                'image' => 'subscribe',
                'brand' => $this->pageTitle,
                'heading' => 'Confirm updates',
                'paragraphs' => ["Someone, hopefully you, asked for email updates from {$this->pageTitle}. Confirm and we’ll email you when something goes wrong and when it’s fixed."],
                'button' => ['label' => 'Confirm updates', 'url' => $this->subscriber->confirmUrl()],
                'note' => 'If it wasn’t you, ignore this email and nothing else will arrive.',
                'footer' => ["Sent by ServerOS for {$this->pageTitle}."],
            ]),
        );
    }
}
