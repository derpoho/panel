<?php

namespace App\Support\Monitors;

use App\Models\Activity;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Support\StatusPages\StatusSubscriptions;
use Illuminate\Support\Collection;

/**
 * Checks monitors and acts on what they find: records the check, moves the
 * monitor between up and down, opens and closes incidents, and alerts.
 */
class MonitorRunner
{
    /** HTTP checks sent together. */
    public const BATCH = 50;

    /** How often a certificate's expiry is read again. */
    public const CERTIFICATE_EVERY_HOURS = 6;

    public function __construct(
        private readonly MonitorProbe $probe,
        private readonly MonitorAlerts $alerts,
    ) {}

    /**
     * Check every monitor that's due, in workspaces with a plan.
     *
     * @return int monitors checked
     */
    public function runDue(): int
    {
        $due = Monitor::query()
            ->with('workspace')
            ->where('paused', false)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')
            ->limit(1000)
            ->get()
            ->filter(fn (Monitor $m) => $m->workspace->isActive());

        foreach ($due->chunk(self::BATCH) as $batch) {
            $this->run($batch);
        }

        return $due->count();
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     */
    public function run(Collection $monitors): void
    {
        $results = $this->probe->check($monitors);

        foreach ($monitors as $monitor) {
            if (isset($results[$monitor->id])) {
                $this->apply($monitor, $results[$monitor->id]);
            }
        }
    }

    public function apply(Monitor $monitor, ProbeResult $result): void
    {
        $now = now();

        MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => $now,
            'ok' => $result->ok,
            'response_ms' => $result->responseMs,
            'status_code' => $result->statusCode,
            'error' => $result->error,
        ]);

        $monitor->recordDay($result->ok, $result->responseMs, $now);
        $monitor->last_checked_at = $now;
        $monitor->last_response_ms = $result->responseMs;
        $monitor->last_error = $result->error;

        if ($result->ok) {
            $wasDown = $monitor->status === 'down';
            if ($monitor->status !== 'up') {
                $monitor->status = 'up';
                $monitor->status_changed_at = $now;
            }
            $monitor->failures = 0;
            $monitor->next_check_at = $now->copy()->addSeconds($monitor->interval_seconds);
            $monitor->save();

            if ($wasDown) {
                $incident = $monitor->openIncident()->first();
                $incident?->update(['resolved_at' => $now]);
                Activity::system($monitor->workspace_id, 'monitor.up', $monitor->name, $monitor->machine_id ? $monitor->machine : null, [
                    'monitor_id' => $monitor->id,
                    'seconds' => $incident ? (int) $incident->started_at->diffInSeconds($now) : null,
                ]);
                $this->alerts->recovered($monitor, $incident);
                $this->tellSubscribers($monitor, $incident, down: false);
            }
        } else {
            $monitor->failures++;
            $confirmed = $monitor->failures >= Monitor::CONFIRM_AFTER;
            $goesDown = $confirmed && $monitor->status !== 'down';

            if ($goesDown) {
                $monitor->status = 'down';
                $monitor->status_changed_at = $now;
            }
            // Not sure yet: look again soon rather than a whole interval on.
            $monitor->next_check_at = $now->copy()->addSeconds($confirmed ? $monitor->interval_seconds : min(30, $monitor->interval_seconds));
            $monitor->save();

            if ($goesDown) {
                $incident = $monitor->incidents()->create([
                    'started_at' => $now,
                    'cause' => $result->error ?? 'Check failed.',
                ]);
                Activity::system($monitor->workspace_id, 'monitor.down', $monitor->name, $monitor->machine_id ? $monitor->machine : null, [
                    'monitor_id' => $monitor->id,
                    'cause' => $incident->cause,
                ]);
                $this->alerts->down($monitor, $incident);
                $this->tellSubscribers($monitor, $incident, down: true);
            }
        }

        $this->certificate($monitor);
    }

    /**
     * Email the people subscribed to status pages that show it. Not while
     * its machine is in planned maintenance, which the page already says.
     */
    private function tellSubscribers(Monitor $monitor, ?MonitorIncident $incident, bool $down): void
    {
        if ($monitor->machine?->inMaintenance()) {
            return;
        }
        StatusSubscriptions::outage($monitor, $incident, $down);
    }

    private function certificate(Monitor $monitor): void
    {
        if (! config('monitors.check_certificates') || ! $monitor->isHttps()) {
            return;
        }
        if ($monitor->ssl_checked_at !== null && $monitor->ssl_checked_at->gt(now()->subHours(self::CERTIFICATE_EVERY_HOURS))) {
            return;
        }

        $expires = $this->probe->certificateExpiry($monitor);
        $monitor->ssl_checked_at = now();
        if ($expires !== null) {
            $monitor->ssl_expires_at = $expires;
        }

        $soon = $monitor->ssl_expires_at !== null && $monitor->ssl_expires_at->lt(now()->addDays(Monitor::SSL_WARN_DAYS));
        if (! $soon) {
            // Renewed: alert again next time it gets close.
            $monitor->ssl_alerted_at = null;
        } elseif ($monitor->ssl_alerted_at === null && ! $monitor->muted()) {
            $monitor->ssl_alerted_at = now();
            $monitor->save();
            $this->alerts->certificateExpiring($monitor);

            return;
        }

        $monitor->save();
    }
}
