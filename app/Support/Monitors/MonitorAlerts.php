<?php

namespace App\Support\Monitors;

use App\Enums\WorkspaceRole;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells people about a monitor: an email to the workspace's owners and
 * admins, and a post to its webhook (Slack and Discord both read it).
 */
class MonitorAlerts
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function down(Monitor $monitor, MonitorIncident $incident): void
    {
        $this->send($monitor, 'monitor.down', "{$monitor->name} is down", [
            $monitor->target(),
            $incident->cause,
        ], bad: true);
    }

    public function recovered(Monitor $monitor, ?MonitorIncident $incident): void
    {
        $after = $incident !== null
            ? 'Back after '.self::duration((int) $incident->started_at->diffInSeconds($incident->resolved_at ?? now())).' down.'
            : 'Back up.';

        $this->send($monitor, 'monitor.up', "{$monitor->name} is back up", [
            $monitor->target(),
            $after,
        ], bad: false);
    }

    public function certificateExpiring(Monitor $monitor): void
    {
        $days = max(0, (int) floor(now()->diffInDays($monitor->ssl_expires_at, false)));

        $this->send($monitor, 'certificate.expiring', "{$monitor->name}’s certificate expires in {$days} ".($days === 1 ? 'day' : 'days'), [
            $monitor->target(),
            'It expires on '.$monitor->ssl_expires_at?->toFormattedDayDateString().'. Renew it before then, or visitors get a security warning.',
        ], bad: true);
    }

    /**
     * Send a test to the webhook. Null on success, otherwise what went
     * wrong.
     */
    public function test(Workspace $workspace, string $url): ?string
    {
        return $this->post($url, [
            'event' => 'test',
            'text' => "Test from ServerOS: alerts for {$workspace->name} will arrive here.",
            'content' => "Test from ServerOS: alerts for {$workspace->name} will arrive here.",
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function send(Monitor $monitor, string $event, string $subject, array $lines, bool $bad): void
    {
        if (! $monitor->alerts || $monitor->muted()) {
            return;
        }

        $workspace = $monitor->workspace;
        $this->notify($workspace, $event, $subject, $lines, 'https://'.$workspace->host().'/monitors/'.$monitor->id, $bad, 'Open the monitor', [
            'monitor' => [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'target' => $monitor->target(),
                'status' => $monitor->status,
            ],
        ]);
    }

    /**
     * Email the owners and admins and post to the webhook, as the workspace
     * has them set. Failures are logged, never thrown: an alert that can't
     * go out mustn't stop the check that raised it.
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $extra  more fields for the webhook body
     */
    public function notify(Workspace $workspace, string $event, string $subject, array $lines, string $url, bool $bad, string $actionLabel, array $extra = []): void
    {
        // Switched off for this workspace: still on the page, just not sent.
        if (! AlertKinds::allows($workspace, $event)) {
            return;
        }

        if ($workspace->alert_email ?? true) {
            $recipients = $this->recipients($workspace, $bad);
            if ($recipients->isNotEmpty()) {
                try {
                    Notification::send($recipients, new MonitorAlert($subject, $lines, $url, $bad, $actionLabel, $workspace->id));
                } catch (Throwable $e) {
                    Log::warning('Alert email failed', ['workspace' => $workspace->id, 'event' => $event, 'error' => $e->getMessage()]);
                }
            }
        }

        if (is_string($workspace->alert_webhook_url) && $workspace->alert_webhook_url !== '') {
            $text = $subject.': '.implode(' ', $lines).' '.$url;
            $error = $this->post($workspace->alert_webhook_url, [
                'event' => $event,
                'text' => $text,
                'content' => $text,
                'url' => $url,
                ...$extra,
            ]);
            if ($error !== null) {
                Log::warning('Alert webhook failed', ['workspace' => $workspace->id, 'event' => $event, 'error' => $error]);
            }
        }
    }

    /**
     * Owners and admins, each as they've chosen: every alert, only
     * problems (not "it's back"), or none.
     *
     * @return Collection<int, User>
     */
    private function recipients(Workspace $workspace, bool $bad = true): Collection
    {
        return $workspace->members()
            ->withPivot(['role', 'alert_emails'])
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->get()
            ->filter(fn (User $user) => match ($user->pivot->alert_emails ?? 'all') {
                'none' => false,
                'problems' => $bad,
                default => true,
            })
            ->unique('id')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $url, array $body): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! str_starts_with(strtolower($url), 'https://') || ! is_string($host)) {
            return 'The webhook needs an https:// address.';
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return 'That address can’t be reached from the panel.';
        }
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        try {
            $response = Http::timeout(8)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                ->withHeaders(['User-Agent' => MonitorProbe::USER_AGENT])
                ->post($url, $body);
        } catch (Throwable $e) {
            return 'Couldn’t reach the webhook.';
        }

        return $response->successful() ? null : "The webhook answered {$response->status()}.";
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min',
            $seconds < 86400 => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
            default => intdiv($seconds, 86400).' d '.intdiv($seconds % 86400, 3600).' h',
        };
    }
}
