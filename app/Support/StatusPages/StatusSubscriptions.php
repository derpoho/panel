<?php

namespace App\Support\StatusPages;

use App\Enums\Limit;
use App\Mail\StatusPageUpdate;
use App\Mail\StatusSubscribeConfirm;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\StatusSubscriber;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Mail\Unsubscribes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Email updates from status pages: visitors subscribe (and confirm from
 * the email they get), then hear when a written incident opens, is updated
 * or resolved, and when a monitor the page shows goes down or comes back.
 * Each confirmed address gets one email per event, however many of the
 * workspace's pages it follows, and never after it unsubscribed from
 * everything.
 */
final class StatusSubscriptions
{
    /** How long before asking the same address to confirm again. */
    private const RESEND_AFTER_MINUTES = 60;

    /**
     * Subscribe an address. An unconfirmed one gets the confirm email (not
     * more than once an hour); a confirmed one is left as it is.
     *
     * @throws RuntimeException when the page is full
     */
    public static function subscribe(StatusPage|WorkspaceStatusPage $page, string $email): StatusSubscriber
    {
        $email = Unsubscribes::normalise($email);
        $subscriber = $page->subscribers()->where('email', $email)->first();

        if ($subscriber === null) {
            $workspace = self::workspaceOf($page);
            $limit = $workspace->isActive() ? $workspace->limitOf(Limit::StatusSubscribers) : 0;
            if ($limit !== null && $page->subscribers()->count() >= $limit) {
                throw new RuntimeException('This status page can’t take more subscribers right now.');
            }
            $subscriber = $page->subscribers()->create(['email' => $email, 'token' => Str::random(48)]);
        }

        $due = $subscriber->confirm_sent_at === null || $subscriber->confirm_sent_at->lt(now()->subMinutes(self::RESEND_AFTER_MINUTES));
        if ($subscriber->confirmed_at === null && $due) {
            $subscriber->forceFill(['confirm_sent_at' => now()])->save();
            $subscriber->setRelation('subscribable', $page);
            Mail::to($email)->queue(new StatusSubscribeConfirm($subscriber, self::title($page)));
        }

        return $subscriber;
    }

    /** A written incident was posted, updated or resolved. */
    public static function incident(StatusIncident $incident): void
    {
        $update = $incident->updates()->first();
        if ($update === null) {
            return;
        }

        $subject = match (true) {
            $incident->state === 'resolved' => "Resolved: {$incident->title}",
            $incident->updates()->count() === 1 => $incident->title,
            default => "Update: {$incident->title}",
        };
        $states = ['investigating' => 'Investigating', 'identified' => 'Identified', 'monitoring' => 'Monitoring', 'resolved' => 'Resolved'];

        self::send(self::pagesForIncident($incident), $subject, $incident->title, $states[$update->state] ?? $update->state, $update->message);
    }

    /** A monitor a page shows went down, or came back. */
    public static function outage(Monitor $monitor, ?MonitorIncident $incident, bool $down): void
    {
        $pages = self::pagesForMonitor($monitor);
        if ($pages === []) {
            return;
        }

        if ($down) {
            self::send($pages, "{$monitor->name} is down", "{$monitor->name} is down", 'Down', 'Our checks can’t reach it right now. We’ll email again when it’s back.');
        } else {
            $minutes = $incident ? max(1, (int) round($incident->started_at->diffInSeconds(now()) / 60)) : null;
            self::send($pages, "{$monitor->name} is back up", "{$monitor->name} is back up", 'Resolved', $minutes ? "It’s answering again, after {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' down.' : 'It’s answering again.');
        }
    }

    /**
     * Live public pages that show this incident: its machine's page, and
     * the workspace's page when it covers that machine (or it's about no
     * machine).
     *
     * @return list<StatusPage|WorkspaceStatusPage>
     */
    public static function pagesForIncident(StatusIncident $incident): array
    {
        $pages = [];
        $machinePage = $incident->machine_id ? StatusPage::query()->where('machine_id', $incident->machine_id)->first() : null;
        if ($machinePage !== null && $machinePage->live() && $machinePage->public) {
            $pages[] = $machinePage;
        }

        $fleet = $incident->workspace->fleetStatusPage;
        if ($fleet !== null && $fleet->live() && $fleet->public
            && ($incident->machine === null || $fleet->covers($incident->machine))) {
            $pages[] = $fleet;
        }

        return $pages;
    }

    /**
     * Live public pages that list this monitor: its machine's page when
     * that page shows it, and the workspace's page when it shows the
     * machine or picks the monitor.
     *
     * @return list<StatusPage|WorkspaceStatusPage>
     */
    public static function pagesForMonitor(Monitor $monitor): array
    {
        $pages = [];
        $machine = $monitor->machine;

        if ($machine !== null) {
            $page = $machine->statusPage;
            if ($page !== null && $page->live() && $page->public && self::lists(new StatusReport($page), $monitor)) {
                $pages[] = $page;
            }
        }

        $fleet = $monitor->workspace->fleetStatusPage;
        if ($fleet !== null && $fleet->live() && $fleet->public) {
            $shown = $machine !== null
                ? $fleet->covers($machine) && self::lists(new StatusReport(FleetStatusReport::pageFor($machine, $fleet->show_uptime)), $monitor)
                : in_array($monitor->id, $fleet->monitors ?? [], true);
            if ($shown) {
                $pages[] = $fleet;
            }
        }

        return $pages;
    }

    private static function lists(StatusReport $report, Monitor $monitor): bool
    {
        return in_array('monitor:'.$monitor->id, array_column($report->monitors(), 'key'), true);
    }

    /**
     * One email per confirmed address across these pages, each with its
     * own page's link and unsubscribe link.
     *
     * @param  list<StatusPage|WorkspaceStatusPage>  $pages
     */
    private static function send(array $pages, string $subject, string $heading, string $state, string $message): void
    {
        $sent = [];
        foreach ($pages as $page) {
            foreach ($page->subscribers()->whereNotNull('confirmed_at')->cursor() as $subscriber) {
                if (isset($sent[$subscriber->email]) || Unsubscribes::has($subscriber->email)) {
                    continue;
                }
                $sent[$subscriber->email] = true;
                $subscriber->setRelation('subscribable', $page);
                Mail::to($subscriber->email)->queue(new StatusPageUpdate($subscriber, self::title($page), $subject, $heading, $state, $message));
            }
        }
    }

    public static function title(StatusPage|WorkspaceStatusPage $page): string
    {
        return $page->title ?: ($page instanceof WorkspaceStatusPage ? $page->workspace->name : $page->machine->name);
    }

    private static function workspaceOf(StatusPage|WorkspaceStatusPage $page): Workspace
    {
        return $page instanceof WorkspaceStatusPage ? $page->workspace : $page->machine->workspace;
    }
}
