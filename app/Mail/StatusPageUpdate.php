<?php

namespace App\Mail;

use App\Models\StatusSubscriber;
use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * An incident or outage on a status page someone subscribed to: what
 * happened, where it stands, and a link to the page.
 */
class StatusPageUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StatusSubscriber $subscriber,
        public string $pageTitle,
        public string $subjectLine,
        public string $heading,
        public string $state,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->pageTitle}] {$this->subjectLine}");
    }

    /** One-click unsubscribe, which Gmail and Yahoo expect. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->subscriber->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        // Red while it's down or being looked into, amber once it's found or
        // being watched, green when it's resolved.
        $tone = match (strtolower($this->state)) {
            'resolved' => 'good',
            'identified', 'monitoring' => 'warn',
            default => 'bad',
        };

        return new Content(
            view: 'mail.brand',
            text: 'mail.brand-text',
            with: BrandMail::data([
                'image' => 'status',
                'brand' => $this->pageTitle,
                'heading' => $this->heading,
                'sections' => [['rows' => [['name' => $this->state, 'dot' => $tone, 'value' => now()->utc()->format('H:i').' UTC', 'tone' => 'mono']]]],
                'after' => array_values(array_filter(preg_split('/\R+/', $this->body) ?: [], fn (string $line) => trim($line) !== '')),
                'button' => ['label' => 'Open the status page', 'url' => route('status.show', $this->subscriber->subscribable->slug)],
                'footer' => ["You asked for updates from {$this->pageTitle}. Sent by ServerOS."],
                'unsubscribe' => ['url' => $this->subscriber->unsubscribeUrl(), 'label' => 'Unsubscribe'],
            ]),
        );
    }
}
