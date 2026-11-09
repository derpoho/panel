<?php

namespace App\Mcp\Tools\Monitor;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitors\AlertKinds;

/**
 * Where the workspace's alerts go, as the Alerts dialog shows it. The
 * webhook's address is a secret of sorts (anyone with it can post to the
 * channel), so it's never given back: only that one is set and, to those
 * who may change it, its host and who gets the emails.
 */
final class AlertSettings
{
    /** What sends an alert, as the dialog puts it. */
    public const EVENTS = 'Sent when a monitor goes down or a machine stops reporting, when they’re back, when a disk is 90% full, when memory stays over 92% for 10 minutes, when a service stops or keeps crashing, when a deploy or a backup starts failing and when it works again, and two weeks before a certificate expires.';

    /** @return array<string, mixed> */
    public static function present(McpContext $context): array
    {
        $workspace = $context->workspace;
        $webhook = is_string($workspace->alert_webhook_url) && $workspace->alert_webhook_url !== '' ? $workspace->alert_webhook_url : null;
        $manager = $context->can(WorkspacePermission::ManageMachines);
        $monitors = $workspace->monitors()->with('machine')->get();

        return [
            'email' => [
                'on' => (bool) ($workspace->alert_email ?? true),
                'to' => 'The workspace’s owners and admins, each as they choose in their notification settings: every alert, only problems (not “it’s back”), or none.',
                // Each person's choice is theirs: shown to those who manage alerts.
                'recipients' => $manager ? self::recipients($context) : null,
            ],
            'webhook' => [
                'set' => $webhook !== null,
                'host' => $webhook !== null && $manager ? parse_url($webhook, PHP_URL_HOST) : null,
            ],
            'events' => self::EVENTS,
            // Kinds switched off: not sent by email or webhook, still shown in ServerOS.
            'off' => AlertKinds::muted($workspace),
            'kinds' => AlertKinds::keys(),
            'monitors' => [
                'total' => $monitors->count(),
                'alertsOff' => $monitors->filter(fn (Monitor $m) => ! $m->alerts)->count(),
                'muted' => $monitors->filter(fn (Monitor $m) => $m->alerts && $m->muted())->count(),
            ],
            'canChange' => $manager,
            'url' => $context->url('/monitors'),
        ];
    }

    /**
     * Owners and admins and which alert emails each gets, as MonitorAlerts
     * picks them.
     *
     * @return list<array{name: string, role: string, gets: string}>
     */
    private static function recipients(McpContext $context): array
    {
        return array_values($context->workspace->members()
            ->withPivot(['role', 'alert_emails'])
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->orderBy('name')
            ->get()
            ->unique('id')
            ->map(fn (User $user) => [
                'name' => $user->name,
                'role' => $user->pivot->role,
                'gets' => match ($user->pivot->alert_emails ?? 'all') {
                    'none' => 'none',
                    'problems' => 'problems',
                    default => 'all',
                },
            ])
            ->all());
    }
}
