<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * One push to the team's ntfy topic, opening a page when tapped. Queued,
 * so a slow ntfy never holds up a signup or a webhook.
 */
class SendNtfy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public string $title,
        public string $message,
        public ?string $url = null,
        public string $tag = 'bell',
    ) {}

    public function handle(): void
    {
        $request = Http::timeout(10);
        if (filled($token = config('services.ntfy.token'))) {
            $request = $request->withToken($token);
        }

        // Published as JSON, so a title with £ in it arrives intact.
        $request->post(rtrim(config('services.ntfy.server'), '/'), array_filter([
            'topic' => config('services.ntfy.topic'),
            'title' => $this->title,
            'message' => $this->message,
            'click' => $this->url,
            'tags' => [$this->tag],
        ], fn ($value) => $value !== null))->throw();
    }
}
