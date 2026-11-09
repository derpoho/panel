<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;

/**
 * The setup guide in the dashboard's top bar: steps in a few groups, each
 * done or not, worked out from what's in the workspace. Someone who hid
 * it (here or on the home page) doesn't get it, and nor does anyone once
 * every step is done.
 */
final class SetupGuide
{
    /**
     * @return array{percent: int, groups: list<array{key: string, title: string, locked: string|null, steps: list<array{key: string, title: string, body: string, href: string, done: bool}>}>}|null
     */
    public static function for(Workspace $workspace, User $user): ?array
    {
        $hidden = $workspace->members()
            ->whereKey($user->id)
            ->whereNotNull('user_workspace.onboarding_dismissed_at')
            ->exists();
        if ($hidden) {
            return null;
        }

        $machines = $workspace->machines()
            ->toBase()
            ->selectRaw('count(*) as machines, coalesce(sum(services_count), 0) as services, count(last_backup_at) as backed_up')
            ->first();
        $hasMachine = (int) ($machines->machines ?? 0) > 0;

        $groups = [
            [
                'key' => 'connect',
                'title' => 'Connect your servers',
                'locked' => null,
                'steps' => [
                    self::step('machine', 'Connect a machine', 'Bring a VPS from any provider. What’s already running stays put.', '/machines', $hasMachine),
                    // Done once ServerOS looks after one, not as soon as a scan finds some.
                    self::step('service', 'Adopt or deploy a service', 'Pick up the apps already on it, or ship a new one.', '/services', self::managesAService($workspace)),
                ],
            ],
            [
                'key' => 'safe',
                'title' => 'Keep it running',
                'locked' => null,
                'steps' => [
                    self::step('monitor', 'Add a monitor', 'Hear about it when a site or port stops answering.', '/monitors', $workspace->monitors()->exists()),
                    self::step('backups', 'Back up a machine', 'So a bad deploy is one click to undo.', '/backups', (int) ($machines->backed_up ?? 0) > 0),
                ],
            ],
            [
                'key' => 'team',
                'title' => 'Bring your team',
                'locked' => null,
                'steps' => [
                    self::step('members', 'Invite a teammate', 'Share the workspace, with a role each.', '/settings/members', $workspace->members()->count() > 1 || $workspace->invitations()->exists()),
                    self::step('security', 'Turn on two-factor', 'Or add a passkey, so a password alone can’t get in.', '/settings/security', $user->hasEnabledTwoFactorAuthentication() || $user->passkeys()->exists()),
                ],
            ],
        ];

        // Choosing a plan, where a package gives somewhere to choose one.
        // Never on a self-hosted install, which has no plans.
        $upgrade = app(Extensions::class)->upgradeLink();
        if ($upgrade !== null && ! Edition::selfHosted()) {
            $groups[] = [
                'key' => 'plan',
                'title' => 'Choose a plan',
                'locked' => $hasMachine ? null : 'Unlocks once a machine is connected',
                'steps' => [
                    self::step('plan', 'Pick a plan', 'Priced by how many machines you connect.', $upgrade->path($workspace), $workspace->plan !== null),
                ],
            ];
        }

        $steps = array_merge(...array_column($groups, 'steps'));
        $done = count(array_filter($steps, fn (array $step) => $step['done']));
        if ($done === count($steps)) {
            return null;
        }

        return [
            'percent' => (int) floor($done / count($steps) * 100),
            'groups' => $groups,
        ];
    }

    /** Whether any machine runs a service ServerOS deployed or someone adopted. */
    public static function managesAService(Workspace $workspace): bool
    {
        foreach ($workspace->machines()->whereNotNull('inventory')->get(['inventory']) as $machine) {
            $services = is_array($machine->inventory) ? ($machine->inventory['services'] ?? []) : [];
            foreach (is_array($services) ? $services : [] as $service) {
                if (is_array($service) && ($service['details']['managed'] ?? null) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{key: string, title: string, body: string, href: string, done: bool}
     */
    private static function step(string $key, string $title, string $body, string $href, bool $done): array
    {
        return compact('key', 'title', 'body', 'href', 'done');
    }
}
