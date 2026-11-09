<?php

namespace App\Support\Machines;

use App\Jobs\ClearMachineData;
use App\Models\DaemonJob;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\ServiceSample;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Logs\LogBuffer;
use App\Support\Logs\LogSkipRules;
use App\Support\Logs\LogStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/**
 * Clearing what a machine has sent ServerOS, without removing the machine:
 * its log lines, its usage history, and what discovery found on it. The
 * daemon's connection is never touched (no disconnect, no new
 * certificate); the only thing sent to the machine is the optional rescan
 * after services are cleared, so discovery reports what's running again.
 *
 * What people set up stays: adopted and deployed services, deploy history,
 * backups and their settings, monitors, status pages, service groups, and
 * the names, icons and hidden or muted flags given to services, which find
 * their services again on the next scan.
 *
 * Deletes run in chunks, on the connection the workspace keeps its lines on
 * (LogStore: ours, or a Business workspace's own Postgres). Big clears run
 * in the background (ClearMachineData), and how it went is kept for the
 * settings page to show.
 */
final class MachineDataEraser
{
    public const LOGS = 'logs';

    public const METRICS = 'metrics';

    public const SERVICES = 'services';

    /** @var list<string> */
    public const KINDS = [self::LOGS, self::METRICS, self::SERVICES];

    /** Rows deleted in one statement. */
    public const CHUNK = 2000;

    /** Clears that would delete more rows than this run in the background. */
    public const SYNC_LIMIT = 20_000;

    /**
     * What turning a collecting feature off offers to delete (a machine
     * policy's switches): pass the kinds to clear() with rescan off,
     * so nothing is collected again. Log skip rules and IP masking narrow
     * what's sent rather than stop it: skipping services offers
     * clearServiceLogs() for just those, masking offers the machine's logs.
     */
    private const FEATURES = [
        'logs' => [self::LOGS],
        'log_skip' => [self::LOGS],
        'ip_masking' => [self::LOGS],
        'metrics' => [self::METRICS],
        'usage' => [self::METRICS],
        'telemetry' => [self::METRICS],
        'discovery' => [self::SERVICES],
        'inventory' => [self::SERVICES],
        'services' => [self::SERVICES],
    ];

    private const DAY_MS = 86_400_000;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * The kinds of data a feature collects, for a "turn off X" flow to
     * offer deleting:
     * `$eraser->clear($machine, MachineDataEraser::forFeature('logs'), $user, rescan: false)`.
     *
     * @return list<string>
     */
    public static function forFeature(string $feature): array
    {
        return self::FEATURES[$feature] ?? throw new RuntimeException("No collected data is known for the feature “{$feature}”.");
    }

    /**
     * The services among the machine's stored logs that these skip rules
     * now hold back, with how many lines each sent, most first: what
     * turning on a service rule offers to delete. Counted from the chart's
     * per-minute counts, grouped by service, and matched here the way the
     * daemon matches. Empty when the log store isn't answering.
     *
     * @param  list<string>  $rules
     * @return list<array{service: string, lines: int}>
     */
    public static function skippedServices(Machine $machine, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $matched = [];
        foreach (self::storedServices($machine) as $service => $lines) {
            $service = (string) $service;
            foreach ($rules as $rule) {
                if (LogSkipRules::matchesService($rule, $service)) {
                    $matched[] = ['service' => $service, 'lines' => $lines];
                    break;
                }
            }
        }
        usort($matched, fn (array $a, array $b) => [$b['lines'], $a['service']] <=> [$a['lines'], $b['service']]);

        return $matched;
    }

    /**
     * Log lines the machine has stored, from the chart's counts: what
     * turning on IP masking offers to clear. 0 when the store isn't
     * answering.
     */
    public static function storedLogLines(Machine $machine): int
    {
        return (int) (self::logSize($machine)['lines'] ?? 0);
    }

    /**
     * Delete these services' lines now if it's quick, or in the background
     * if not, like start().
     *
     * @param  list<string>  $services
     * @return array{queued: bool, counts: array<string, int>}
     */
    public function startServiceLogs(Machine $machine, array $services, ?User $user = null): array
    {
        $estimate = array_sum(array_intersect_key(self::storedServices($machine), array_flip($services)));

        if ($estimate > self::SYNC_LIMIT) {
            self::setStatus($machine, ['state' => 'queued', 'kinds' => [self::LOGS], 'counts' => [self::LOGS => $estimate], 'services' => $services]);
            ClearMachineData::dispatch($machine->id, [self::LOGS], $user?->id, false, $services);

            return ['queued' => true, 'counts' => [self::LOGS => $estimate]];
        }

        $counts = [self::LOGS => $this->clearServiceLogs($machine, $services)];
        self::setStatus($machine, ['state' => 'done', 'kinds' => [self::LOGS], 'counts' => $counts, 'services' => $services]);

        return ['queued' => false, 'counts' => $counts];
    }

    /**
     * Clear the kinds now, returning how many of each went.
     *
     * @param  list<string>  $kinds
     * @return array<string, int>
     */
    public function clear(Machine $machine, array $kinds, ?User $user = null, bool $rescan = true): array
    {
        $counts = [];
        foreach (self::only($kinds) as $kind) {
            $counts[$kind] = match ($kind) {
                self::LOGS => $this->clearLogs($machine),
                self::METRICS => $this->clearMetrics($machine),
                default => $this->clearServices($machine, $user, $rescan),
            };
        }

        return $counts;
    }

    /**
     * Clear the kinds now if it's quick, or in the background if not.
     * Counts are what went (now) or what's going (queued), for the activity
     * entry; the status says how it went either way.
     *
     * @param  list<string>  $kinds
     * @return array{queued: bool, counts: array<string, int>}
     */
    public function start(Machine $machine, array $kinds, ?User $user = null, bool $rescan = true): array
    {
        $kinds = self::only($kinds);
        $estimate = $this->estimate($machine, $kinds);

        if (array_sum($estimate) > self::SYNC_LIMIT) {
            self::setStatus($machine, ['state' => 'queued', 'kinds' => $kinds, 'counts' => $estimate]);
            ClearMachineData::dispatch($machine->id, $kinds, $user?->id, $rescan);

            return ['queued' => true, 'counts' => $estimate];
        }

        $counts = $this->clear($machine, $kinds, $user, $rescan);
        self::setStatus($machine, ['state' => 'done', 'kinds' => $kinds, 'counts' => $counts]);

        return ['queued' => false, 'counts' => $counts];
    }

    /**
     * Delete the machine's log lines and the chart's counts of them, in
     * the workspace's store, and any lines still waiting to reach it. The
     * daily allowance isn't given back: it counts lines received, and
     * clearing doesn't un-receive them.
     */
    public function clearLogs(Machine $machine): int
    {
        $connection = LogStore::connection($machine->workspace_id);
        // Lines that arrive while this runs are new: they stay.
        $upTo = (int) LogEntry::on($connection)->where('machine_id', $machine->id)->max('id');
        $deleted = 0;

        while ($upTo > 0) {
            $ids = LogEntry::on($connection)->where('machine_id', $machine->id)->where('id', '<=', $upTo)
                ->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $deleted += LogEntry::on($connection)->whereIn('id', $ids)->delete();
            if (count($ids) < self::CHUNK) {
                break;
            }
        }

        // The chart's per-minute counts, a day at a time along their index.
        $counts = DB::connection($connection)->table('log_counts')->where('machine_id', $machine->id);
        $first = $counts->clone()->min('minute');
        $last = $counts->clone()->max('minute');
        if ($first !== null && $last !== null) {
            for ($from = (int) $first; $from <= (int) $last; $from += self::DAY_MS) {
                $counts->clone()->where('minute', '>=', $from)->where('minute', '<', min($from + self::DAY_MS, (int) $last + 1))->delete();
            }
        }

        return $deleted + LogBuffer::forgetMachine($machine->workspace_id, $machine->id);
    }

    /**
     * Delete the lines these services sent from this machine (by their
     * exact name), and the chart's counts of them, in the workspace's
     * store, and any still waiting to reach it. For services the machine
     * no longer sends: other services and machines are untouched.
     *
     * @param  list<string>  $services
     */
    public function clearServiceLogs(Machine $machine, array $services): int
    {
        $services = array_values(array_unique($services));
        if ($services === []) {
            return 0;
        }
        $connection = LogStore::connection($machine->workspace_id);
        $deleted = 0;

        // A service at a time, along the (machine, service, id) index.
        foreach ($services as $service) {
            $lines = fn () => LogEntry::on($connection)->where('machine_id', $machine->id)->where('service', $service);
            $upTo = (int) $lines()->max('id');
            while ($upTo > 0) {
                $ids = $lines()->where('id', '<=', $upTo)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                $deleted += LogEntry::on($connection)->whereIn('id', $ids)->delete();
                if (count($ids) < self::CHUNK) {
                    break;
                }
            }
        }

        $counts = DB::connection($connection)->table('log_counts')->where('machine_id', $machine->id)->whereIn('service', $services);
        $first = $counts->clone()->min('minute');
        $last = $counts->clone()->max('minute');
        if ($first !== null && $last !== null) {
            for ($from = (int) $first; $from <= (int) $last; $from += self::DAY_MS) {
                $counts->clone()->where('minute', '>=', $from)->where('minute', '<', min($from + self::DAY_MS, (int) $last + 1))->delete();
            }
        }

        return $deleted + LogBuffer::forgetServices($machine->workspace_id, $machine->id, $services);
    }

    /** Delete the machine's usage history and each service's. */
    public function clearMetrics(Machine $machine): int
    {
        $deleted = 0;
        foreach ([MachineSample::class, ServiceSample::class] as $model) {
            while (true) {
                $ids = $model::query()->where('machine_id', $machine->id)->limit(self::CHUNK)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                $deleted += $model::query()->whereIn('id', $ids)->delete();
            }
        }

        return $deleted;
    }

    /**
     * Forget what discovery found: services nobody adopted or deployed,
     * ports, scheduled tasks and firewall rules, and the latest readings of
     * the services that went. Then ask for a fresh scan, unless the person
     * is stopping discovery. Returns how many services went.
     */
    public function clearServices(Machine $machine, ?User $user = null, bool $rescan = true): int
    {
        $inventory = $machine->inventory;
        $removed = 0;

        if (is_array($inventory)) {
            $services = array_values(array_filter(is_array($inventory['services'] ?? null) ? $inventory['services'] : [], 'is_array'));
            $kept = array_values(array_filter($services, fn (array $service) => self::setUp($machine, $service)));
            $removed = count($services) - count($kept);
            $keys = array_filter(array_map(fn (array $service) => $service['key'] ?? null, $kept), 'is_string');

            $machine->forceFill([
                // Never null again: that means "not scanned yet", which
                // would run a new server's setup checks once more.
                'inventory' => ['scanned_at' => null, 'complete' => false, 'services' => $kept, 'listeners' => []],
                // So the next scan is taken, whatever its time.
                'inventory_scanned_at' => null,
                'service_stats' => array_intersect_key($machine->service_stats ?? [], array_flip($keys)) ?: null,
                'services_count' => ServiceLabels::shownCount($machine, $kept),
            ])->save();
        }

        if ($rescan) {
            $this->rescan($machine, $user);
        }

        return $removed;
    }

    /**
     * Rows each kind would delete, roughly: to decide whether a clear runs
     * in the background, and to say what's going.
     *
     * @param  list<string>  $kinds
     * @return array<string, int>
     */
    public function estimate(Machine $machine, array $kinds): array
    {
        $estimate = [];
        foreach (self::only($kinds) as $kind) {
            $estimate[$kind] = match ($kind) {
                self::LOGS => (int) (self::logSize($machine)['lines'] ?? 0),
                self::METRICS => self::metricRows($machine),
                default => count(self::discovered($machine)),
            };
        }

        return $estimate;
    }

    /**
     * How much of each kind the machine has, for its settings page. Log
     * lines are counted from the chart's per-minute counts, not the lines
     * themselves, so it stays quick with millions of them.
     *
     * @return array{logs: array{lines: int|null, since: string|null, unavailable: bool}, metrics: array{readings: int, since: string|null}, services: array{discovered: int, kept: int, scannedAt: string|null}, status: array<string, mixed>|null}
     */
    public function summary(Machine $machine): array
    {
        $samplesSince = collect([
            MachineSample::query()->where('machine_id', $machine->id)->min('ts'),
            ServiceSample::query()->where('machine_id', $machine->id)->min('ts'),
        ])->filter(fn ($ts) => $ts !== null)->min();
        $scannedAt = $machine->inventory['scanned_at'] ?? null;
        $discovered = count(self::discovered($machine));
        $services = is_array($machine->inventory['services'] ?? null) ? count($machine->inventory['services']) : 0;

        return [
            'logs' => self::logSize($machine),
            'metrics' => [
                'readings' => self::metricRows($machine),
                'since' => $samplesSince === null ? null : CarbonImmutable::createFromTimestamp((int) $samplesSince)->toIso8601String(),
            ],
            'services' => [
                'discovered' => $discovered,
                'kept' => $services - $discovered,
                'scannedAt' => is_int($scannedAt) ? CarbonImmutable::createFromTimestamp($scannedAt)->toIso8601String() : null,
            ],
            'status' => self::status($machine),
        ];
    }

    /**
     * The machine's latest clear: queued, running, done or failed, with
     * its kinds and counts. Kept a day.
     *
     * @return array<string, mixed>|null
     */
    public static function status(Machine|int $machine): ?array
    {
        $status = Cache::get(self::statusKey($machine));

        return is_array($status) ? $status : null;
    }

    /** @param  array<string, mixed>  $status */
    public static function setStatus(Machine|int $machine, array $status): void
    {
        Cache::put(self::statusKey($machine), [...$status, 'at' => now()->toIso8601String()], now()->addDay());
    }

    /**
     * The activity entry's details: how much of each kind went (or is
     * going, when queued).
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public static function activityMeta(array $counts, bool $queued): array
    {
        return array_filter([...$counts, 'queued' => $queued ?: null], fn ($value) => $value !== null);
    }

    /**
     * The kinds asked for, known ones only, in their usual order.
     *
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function only(array $kinds): array
    {
        return array_values(array_intersect(self::KINDS, $kinds));
    }

    /**
     * Whether a service is one someone set up, kept through a clear:
     * adopted or created by ServerOS, or a version of a deployed app.
     *
     * @param  array<string, mixed>  $service
     */
    public static function setUp(Machine $machine, array $service): bool
    {
        if (($service['details']['managed'] ?? null) === 'true') {
            return true;
        }
        $release = AppReleases::parse(is_string($service['name'] ?? null) ? $service['name'] : null);

        return $release !== null && isset(AppReleases::live($machine)[$release['app']]);
    }

    /**
     * The services a clear would forget.
     *
     * @return list<array<string, mixed>>
     */
    private static function discovered(Machine $machine): array
    {
        $services = is_array($machine->inventory['services'] ?? null) ? $machine->inventory['services'] : [];

        return array_values(array_filter($services, fn ($service) => is_array($service) && ! self::setUp($machine, $service)));
    }

    /** @return array{lines: int|null, since: string|null, unavailable: bool} */
    private static function logSize(Machine $machine): array
    {
        try {
            $counts = LogStore::counts($machine->workspace_id)->where('machine_id', $machine->id);
            $lines = (int) $counts->clone()->sum('n');
            $since = $counts->clone()->min('minute');
        } catch (QueryException|PDOException) {
            // Their own store isn't answering: say so rather than fail.
            return ['lines' => null, 'since' => null, 'unavailable' => true];
        }

        return [
            'lines' => $lines,
            'since' => $since === null ? null : CarbonImmutable::createFromTimestampMs((int) $since)->toIso8601String(),
            'unavailable' => false,
        ];
    }

    /**
     * Lines each service has stored for the machine, by exact name: one
     * grouped sum over the chart's counts, selecting only the service and
     * its total so it runs the same on Postgres and SQLite.
     *
     * @return array<string, int>
     */
    private static function storedServices(Machine $machine): array
    {
        try {
            return LogStore::counts($machine->workspace_id)
                ->where('machine_id', $machine->id)
                ->groupBy('service')
                ->select('service')
                ->selectRaw('sum(n) as total')
                ->pluck('total', 'service')
                ->map(fn ($total) => (int) $total)
                ->filter(fn (int $total) => $total > 0)
                ->all();
        } catch (QueryException|PDOException) {
            return [];
        }
    }

    private static function metricRows(Machine $machine): int
    {
        return MachineSample::query()->where('machine_id', $machine->id)->count()
            + ServiceSample::query()->where('machine_id', $machine->id)->count();
    }

    /**
     * Ask the daemon for a fresh scan, as the person (or the owner). When
     * it can't be sent now, it's sent when the machine next reports in.
     */
    private function rescan(Machine $machine, ?User $user): void
    {
        $user ??= $machine->workspace?->owner;
        if ($user === null || $this->queue->unavailable($machine) !== null) {
            $machine->forceFill(['rescan_wanted_at' => now()])->save();

            return;
        }

        $running = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'discover')
            ->whereIn('state', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(2))->exists();
        if ($running) {
            return;
        }

        try {
            $this->queue->queue($machine, $user, 'discover', ['type' => 'discover'], null, 120);
        } catch (RuntimeException $e) {
            report($e);
            $machine->forceFill(['rescan_wanted_at' => now()])->save();
        }
    }

    private static function statusKey(Machine|int $machine): string
    {
        return 'machine-data-clear:'.($machine instanceof Machine ? $machine->id : $machine);
    }
}
