<?php

namespace App\Support\Health;

use App\Enums\MachineEnvironment;
use App\Models\Activity;
use App\Models\Machine;
use App\Support\Monitors\MonitorAlerts;

/**
 * Alerts when a server stops reporting or its disk nearly fills, once per
 * occurrence, and says so again when it's over. What's been alerted is kept
 * on the machine so a flapping connection or a disk hovering at the line
 * doesn't send a stream of messages.
 */
class MachineWatcher
{
    /** Quiet this long before "stopped reporting" goes out: rides out restarts and updates. */
    public const OFFLINE_ALERT_SECONDS = 300;

    /** Disk alert at this, cleared below DISK_CLEAR. */
    public const DISK_ALERT = 90;

    public const DISK_CLEAR = 85;

    /**
     * Memory alert once it's been at MEMORY_ALERT% or more for
     * MEMORY_ALERT_MINUTES (a spike from a build or a backup says nothing),
     * cleared below MEMORY_CLEAR%. The same line as Needs attention.
     */
    public const MEMORY_ALERT = WorkspaceHealth::MEMORY_WARN;

    public const MEMORY_ALERT_MINUTES = 10;

    public const MEMORY_CLEAR = 85;

    public function __construct(
        private readonly MonitorAlerts $alerts,
        private readonly ServiceWatcher $services,
    ) {}

    /**
     * @return int alerts sent
     */
    public function run(): int
    {
        $sent = 0;

        Machine::query()
            ->with('workspace')
            ->whereNotNull('uid')
            ->whereNotNull('last_seen_at')
            ->chunkById(200, function ($machines) use (&$sent): void {
                foreach ($machines as $machine) {
                    if ($machine->workspace->isActive()) {
                        $sent += $this->check($machine);
                    }
                }
            });

        return $sent;
    }

    public function check(Machine $machine): int
    {
        $state = $machine->alert_state ?? [];
        $before = $state;
        $sent = 0;
        $url = 'https://'.$machine->workspace->host().'/machines/'.$machine->id;
        // Silent for the whole grace period, whether the connection dropped or
        // just went quiet: a daemon restarting into an update disconnects for
        // a few seconds, which is nobody's emergency.
        $quiet = $machine->last_seen_at->lt(now()->subSeconds(self::OFFLINE_ALERT_SECONDS));
        $online = $machine->isOnline();

        // Development boxes come and go; they still show on Home.
        $watchOffline = $machine->environment !== MachineEnvironment::Development;
        // Planned work: say nothing new now. Whatever is still wrong when the
        // window ends is alerted then.
        $maintenance = $machine->inMaintenance();

        if ($quiet && $watchOffline && ! isset($state['offline']) && $machine->last_seen_at->lt(now()->subDay())) {
            // Long gone before anyone was watching (a server retired without
            // being removed): note it, but don't page anyone about old news.
            $state['offline'] = $machine->last_seen_at->getTimestamp();
        } elseif ($quiet && $watchOffline && ! isset($state['offline']) && ! $maintenance) {
            $state['offline'] = $machine->last_seen_at->getTimestamp();
            Activity::system($machine->workspace_id, 'machine.offline', $machine->name, $machine);
            $this->alerts->notify($machine->workspace, 'machine.offline', "{$machine->name} stopped reporting", [
                "Nothing from it since {$machine->last_seen_at->diffForHumans()}.",
                'It may be down or unreachable, or the ServerOS daemon stopped. Check it in your provider’s console; if it’s running, restart the daemon with “systemctl restart serverosd”.',
            ], $url, true, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name]]);
            $sent++;
        } elseif ($online && isset($state['offline'])) {
            $seconds = now()->getTimestamp() - $state['offline'];
            unset($state['offline']);
            Activity::system($machine->workspace_id, 'machine.online', $machine->name, $machine, ['seconds' => $seconds]);
            $maintenance || $this->alerts->notify($machine->workspace, 'machine.online', "{$machine->name} is reporting again", [
                'Back after '.MonitorAlerts::duration($seconds).' without a report.',
            ], $url, false, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name]]);
            $sent += $maintenance ? 0 : 1;
        }

        if ($online && $machine->disk_percent !== null) {
            if ($machine->disk_percent >= self::DISK_ALERT && ! isset($state['disk']) && ! $maintenance) {
                $state['disk'] = now()->getTimestamp();
                $disk = $machine->disks[0] ?? null;
                $free = $disk ? WorkspaceHealth::bytes($disk['total'] - $disk['used']) : null;
                Activity::system($machine->workspace_id, 'machine.disk_full', $machine->name, $machine, ['percent' => $machine->disk_percent, 'mount' => $disk['mount'] ?? null]);
                $this->alerts->notify($machine->workspace, 'machine.disk', "{$machine->name}’s disk is {$machine->disk_percent}% full", [
                    ($disk ? ($disk['mount'] === '/' ? 'The main disk' : "The disk at {$disk['mount']}")." has {$free} left." : 'It’s nearly out of space.'),
                    'When it fills, databases stop writing and apps usually crash. “See what’s using it” on the machine’s page shows what’s filling it and what’s safe to clear, or resize the disk.',
                ], $url, true, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name, 'disk_percent' => $machine->disk_percent]]);
                $sent++;
            } elseif ($machine->disk_percent < self::DISK_CLEAR && isset($state['disk'])) {
                unset($state['disk']);
            }
        }

        $sent += $this->memory($machine, $state, $online && ! $maintenance, $url);

        if ($online) {
            $services = $state['services'] ?? [];
            $sent += $this->services->check($machine, $services, $maintenance);
            $state['services'] = $services;
        }

        if ($state !== $before) {
            $machine->forceFill(['alert_state' => $state === [] ? null : $state])->saveQuietly();
        }

        return $sent;
    }

    /**
     * Memory that stays high: when it went over is kept as "memory_high",
     * and "memory" once alerted, so it's said once and again when it's
     * back to normal.
     *
     * @param  array<string, mixed>  $state
     * @return int alerts sent
     */
    private function memory(Machine $machine, array &$state, bool $watch, string $url): int
    {
        $total = (int) ($machine->facts['memory_bytes'] ?? 0);
        if (! $watch || $total <= 0 || $machine->memory_used_mb === null) {
            // It has to stay high while we can see it.
            unset($state['memory_high']);

            return 0;
        }
        $percent = (int) floor($machine->memory_used_mb * 1048576 / $total * 100);
        $extra = ['machine' => ['id' => $machine->id, 'name' => $machine->name, 'memory_percent' => $percent]];

        if ($percent >= self::MEMORY_ALERT) {
            $state['memory_high'] ??= now()->getTimestamp();
            if (isset($state['memory']) || now()->getTimestamp() - $state['memory_high'] < self::MEMORY_ALERT_MINUTES * 60) {
                return 0;
            }
            $state['memory'] = now()->getTimestamp();
            $this->alerts->notify($machine->workspace, 'machine.memory', "{$machine->name} is using {$percent}% of its memory", [
                'It’s been over '.self::MEMORY_ALERT.'% for '.MonitorAlerts::duration(now()->getTimestamp() - $state['memory_high']).'.',
                'When memory runs out, Linux stops the biggest process, often your database. See which service uses most on the machine page, then give it a limit or move to a bigger server.',
            ], $url, true, 'Open the machine', $extra);

            return 1;
        }

        // Any dip below the line starts the wait again.
        unset($state['memory_high']);
        if ($percent < self::MEMORY_CLEAR) {
            if (isset($state['memory'])) {
                unset($state['memory']);
                $this->alerts->notify($machine->workspace, 'machine.memory_ok', "{$machine->name}’s memory is back to normal", [
                    "It’s using {$percent}% now.",
                ], $url, false, 'Open the machine', $extra);

                return 1;
            }
        }

        return 0;
    }
}
