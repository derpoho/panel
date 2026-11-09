<?php

namespace App\Support\Health;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Monitors\MonitorAlerts;
use RuntimeException;

/**
 * Notices a service that stopped or keeps crashing.
 *
 * Telemetry lists running services every minute; the full inventory comes
 * from hourly scans. When a service that was running drops out of
 * telemetry, a fresh scan is asked for, and the scan decides: still there
 * but stopped or failed means it went down; gone means it was replaced
 * (a redeploy gives a container a new id) and nothing is said. Services
 * someone stopped on purpose are left alone, and so are app versions a
 * deploy retired (see AppReleases).
 *
 * State lives in the machine's alert_state under "services":
 *   running: keys running at the last look
 *   down: {key: ts} alerted as stopped
 *   restarts: {key: [count, ts]} restart count baseline
 *   looping: {key: ts} alerted as crash-looping
 *   rescan_at: when a scan was last asked for
 */
class ServiceWatcher
{
    /** Restarts within the window that count as a crash loop. */
    public const LOOP_RESTARTS = 3;

    public const LOOP_WINDOW_SECONDS = 900;

    /** Quiet this long after a loop before it counts as settled. */
    public const LOOP_SETTLED_SECONDS = 1800;

    public const RESCAN_EVERY_SECONDS = 600;

    public function __construct(
        private readonly MonitorAlerts $alerts,
        private readonly DaemonJobQueue $queue,
    ) {}

    /**
     * During maintenance nothing is said: what was running before stays the
     * baseline, so a service still down when the window ends is noticed
     * then, and restarts in the window don't count toward a loop.
     *
     * @param  array<string, mixed>  $state  the machine's services state, updated in place
     * @return int alerts sent
     */
    public function check(Machine $machine, array &$state, bool $maintenance = false): int
    {
        $inventory = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            // A deploy stops the version it replaces; that's not an outage.
            if (is_array($service) && is_string($service['key'] ?? null) && preg_match('/^(docker|systemd):/', $service['key']) === 1
                && ! AppReleases::retired($machine, $service) && ! ServiceLabels::isHidden($machine, $service)) {
                $inventory[$service['key']] = $service;
            }
        }
        if ($inventory === []) {
            return 0;
        }

        $sent = 0;
        $now = now()->getTimestamp();
        $stats = $machine->service_stats ?? [];

        $this->askForScanIfMissing($machine, $inventory, $stats, $state, $now);

        // Stopped or recovered, by the inventory.
        $running = array_keys(array_filter($inventory, fn (array $s) => in_array($s['status'] ?? null, ['running', 'restarting'], true)));
        $previous = $state['running'] ?? null;
        $state['down'] ??= [];

        if (is_array($previous) && ! $maintenance) {
            foreach (array_diff($previous, $running) as $key) {
                $service = $inventory[$key] ?? null;
                if ($service === null || ! in_array($service['status'] ?? null, ['stopped', 'failed'], true) || isset($state['down'][$key])) {
                    continue;
                }
                if ($this->stoppedOnPurpose($machine, $key) || self::replacedSwarmTask($inventory, $running, $key)) {
                    continue;
                }

                $state['down'][$key] = $now;
                $name = self::label($machine, $service, $key);
                Activity::system($machine->workspace_id, 'service.down', $name, $machine, ['status' => $service['status'], 'key' => $key]);
                $this->alert($machine, $key, $name, 'service.down', "{$name} stopped on {$machine->name}", [
                    $service['status'] === 'failed'
                        ? 'It was running and has now failed.'
                        : 'It was running and has now stopped, and nobody stopped it from ServerOS.',
                    'Its logs usually say why: a crash, running out of memory, or a bad config after a change.',
                ], true);
                $sent++;
            }
        }

        foreach ($state['down'] as $key => $since) {
            // Gone, or Swarm has since started its replacement: not back, just moot.
            if (! isset($inventory[$key]) || self::replacedSwarmTask($inventory, $running, $key)) {
                unset($state['down'][$key]);
            } elseif (in_array($key, $running, true)) {
                unset($state['down'][$key]);
                $name = self::label($machine, $inventory[$key], $key);
                Activity::system($machine->workspace_id, 'service.up', $name, $machine, ['seconds' => $now - $since, 'key' => $key]);
                if ($maintenance) {
                    continue;
                }
                $this->alert($machine, $key, $name, 'service.up', "{$name} is running again on {$machine->name}", [
                    'Back after '.MonitorAlerts::duration($now - $since).'.',
                ], false);
                $sent++;
            }
        }
        if (! $maintenance || ! is_array($previous)) {
            $state['running'] = $running;
        }

        // Crash loops, by the restart count telemetry reports.
        $state['restarts'] ??= [];
        $state['looping'] ??= [];
        foreach ($stats as $key => $stat) {
            $count = is_int($stat['restarts'] ?? null) ? $stat['restarts'] : null;
            if ($count === null || ! isset($inventory[$key])) {
                continue;
            }

            $baseline = $state['restarts'][$key] ?? null;
            if (! is_array($baseline) || ! is_int($baseline[0] ?? null) || ! is_int($baseline[1] ?? null) || $count < $baseline[0] || $maintenance) {
                // First look, or a new container counting from zero.
                $state['restarts'][$key] = [$count, $now];

                continue;
            }
            [$base, $at] = $baseline;
            $looping = isset($state['looping'][$key]);

            if ($count - $base >= self::LOOP_RESTARTS && $now - $at <= self::LOOP_WINDOW_SECONDS) {
                $state['restarts'][$key] = [$count, $now];
                if ($looping) {
                    continue;
                }

                $state['looping'][$key] = $now;
                $name = self::label($machine, $inventory[$key], $key);
                $restarts = $count - $base;
                Activity::system($machine->workspace_id, 'service.crash_loop', $name, $machine, ['restarts' => $restarts, 'key' => $key]);
                $this->alert($machine, $key, $name, 'service.crash_loop', "{$name} keeps restarting on {$machine->name}", [
                    "It restarted {$restarts} times in ".MonitorAlerts::duration(max(60, $now - $at)).': it starts, crashes and is started again.',
                    'The error is in its logs, usually in the last lines before each restart.',
                ], true);
                $sent++;
            } elseif ($count === $base && $looping && $now - $at >= self::LOOP_SETTLED_SECONDS) {
                // No restarts for half an hour: it has settled.
                unset($state['looping'][$key]);
                $state['restarts'][$key] = [$count, $now];
            } elseif ($now - $at > self::LOOP_WINDOW_SECONDS && ! ($looping && $count === $base)) {
                // The window passed without a loop: count from here.
                $state['restarts'][$key] = [$count, $now];
            }
        }
        foreach (array_keys($state['restarts']) as $key) {
            if (! isset($inventory[$key])) {
                unset($state['restarts'][$key], $state['looping'][$key]);
            }
        }

        return $sent;
    }

    /**
     * A service the last scan saw running that telemetry no longer lists:
     * ask for a scan so the inventory catches up.
     *
     * @param  array<string, array<string, mixed>>  $inventory
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $state
     */
    private function askForScanIfMissing(Machine $machine, array $inventory, array $stats, array &$state, int $now): void
    {
        $latest = 0;
        foreach ($stats as $stat) {
            $latest = max($latest, is_array($stat) ? (int) ($stat['at'] ?? 0) : 0);
        }
        if ($latest === 0 || $now - ($state['rescan_at'] ?? 0) < self::RESCAN_EVERY_SECONDS) {
            return;
        }

        $missing = false;
        foreach ($inventory as $key => $service) {
            if (($service['status'] ?? null) === 'running' && (! isset($stats[$key]) || (int) ($stats[$key]['at'] ?? 0) < $latest - 120)) {
                $missing = true;
                break;
            }
        }
        if (! $missing) {
            return;
        }

        $owner = $machine->workspace->owner;
        if ($owner === null || $this->queue->unavailable($machine) !== null) {
            return;
        }

        $state['rescan_at'] = $now;
        try {
            $this->queue->queue($machine, $owner, 'discover', ['type' => 'discover'], null, 120);
        } catch (RuntimeException) {
            // The hourly scan catches up.
        }
    }

    /**
     * Docker Swarm runs each replica as a container named
     * `<service>.<slot>.<task id>` (the slot is the node id for a global
     * service) and replaces it with a new task when it restarts, updates or
     * moves. The old task's container stays behind, stopped. That's Swarm
     * doing its job, not an outage, when another task in the same slot is
     * running.
     *
     * @param  array<string, array<string, mixed>>  $inventory
     * @param  list<string>  $running
     */
    public static function replacedSwarmTask(array $inventory, array $running, string $key): bool
    {
        $slot = self::swarmSlot($inventory[$key]['name'] ?? null);
        if ($slot === null) {
            return false;
        }

        foreach ($running as $other) {
            if ($other !== $key && self::swarmSlot($inventory[$other]['name'] ?? null) === $slot) {
                return true;
            }
        }

        return false;
    }

    /** `web.1` for `web.1.k2j3…`, or null when the name isn't a Swarm task's. */
    private static function swarmSlot(mixed $name): ?string
    {
        return is_string($name) && preg_match('/^\/?(.+\.(?:\d+|[a-z0-9]{25}))\.[a-z0-9]{25}$/', $name, $match) === 1 ? $match[1] : null;
    }

    /**
     * Whether someone stopped or removed it from ServerOS in the last day.
     */
    private function stoppedOnPurpose(Machine $machine, string $key): bool
    {
        $job = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $key)
            ->whereIn('kind', ['service_action', 'service_remove'])
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->first();

        return $job !== null && ($job->kind === 'service_remove' || ($job->command['job']['action'] ?? null) === 'stop');
    }

    /**
     * @param  list<string>  $lines
     */
    private function alert(Machine $machine, string $key, string $name, string $event, string $subject, array $lines, bool $bad): void
    {
        $url = 'https://'.$machine->workspace->host().'/machines/'.$machine->id.'/services/'.$key;
        $this->alerts->notify($machine->workspace, $event, $subject, $lines, $url, $bad, 'Open the service', [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'service' => ['key' => $key, 'name' => $name],
        ]);
    }

    /**
     * What to call a service in alerts: the name someone gave it, or a
     * deployed app's own name, not its current version's container.
     *
     * @param  array<string, mixed>  $service
     */
    private static function label(Machine $machine, array $service, string $key): string
    {
        return ServiceLabels::display($machine, $service) ?: $key;
    }
}
