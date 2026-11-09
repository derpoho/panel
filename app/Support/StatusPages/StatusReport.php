<?php

namespace App\Support\StatusPages;

use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a status page shows: each service it lists (grouped like on the
 * machine), whether it's up now, and its uptime day by day. Built from the
 * machine's latest inventory and the counts the page has recorded.
 *
 * @phpstan-type Uptime array{percent: float|null, days: list<float|null>}
 * @phpstan-type Component array{key: string, label: string, status: string, group: string|null, uptime: Uptime|null, responseMs?: int|null}
 * @phpstan-type Incident array{label: string, startedAt: string, resolvedAt: string|null, seconds: int}
 * @phpstan-type Upcoming array{startsAt: string, endsAt: string, reason: string|null, machine: string|null}
 * @phpstan-type Notice array{id: int, title: string, impact: string, state: string, machine: string|null, startedAt: string, resolvedAt: string|null, updates: list<array{state: string, message: string, at: string}>}
 * @phpstan-type Report array{title: string, description: string|null, notice: array{text: string, kind: string, reason?: string|null, until?: string}|null, overall: array{status: string, label: string}, server: string, sections: list<array{name: string|null, components: list<Component>}>, uptimePercent: float|null, days: list<string>, updatedAt: string|null, checkedAt: string|null, incidents: list<Incident>|null, upcoming: list<Upcoming>, notices: list<Notice>}
 */
final class StatusReport
{
    public const OPERATIONAL = 'operational';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    /** The server isn't reporting, so nothing is known. */
    public const UNKNOWN = 'unknown';

    /** Days of incidents the page lists. */
    public const INCIDENT_DAYS = 14;

    /** @var Collection<int, Monitor>|null */
    private ?Collection $monitors = null;

    public function __construct(private readonly StatusPage $page) {}

    /**
     * The machine's monitors, paused ones left out: they say nothing about
     * whether it's up.
     *
     * @return Collection<int, Monitor>
     */
    public function allMonitors(): Collection
    {
        return $this->monitors ??= Monitor::query()
            ->where('machine_id', $this->page->machine->id)
            ->where('paused', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * The monitors the page lists, keyed monitor:<id> in its settings
     * like services are keyed by discovery.
     *
     * @return list<array{key: string, monitor: Monitor, label: string, status: string, section: string|null}>
     */
    public function monitors(): array
    {
        $config = $this->page->services ?? [];
        $rows = [];

        foreach ($this->allMonitors() as $monitor) {
            $key = 'monitor:'.$monitor->id;
            if (! ($config[$key]['shown'] ?? $this->page->include_new)) {
                continue;
            }
            $status = self::monitorStatus($monitor);
            // Only while there's a problem: off the page while it's fine.
            if (($config[$key]['quiet'] ?? false) && $status === self::OPERATIONAL) {
                continue;
            }
            $label = trim((string) ($config[$key]['label'] ?? ''));
            $section = trim((string) ($config[$key]['section'] ?? ''));
            $rows[] = [
                'key' => $key,
                'monitor' => $monitor,
                'label' => $label !== '' ? $label : $monitor->name,
                'status' => $status,
                // Its own section on the page, apart from the other monitors.
                'section' => $section !== '' ? $section : null,
            ];
        }

        return $rows;
    }

    /** A monitor's state as the page shows it. */
    public static function monitorStatus(Monitor $monitor): string
    {
        return match ($monitor->status) {
            'up' => self::OPERATIONAL,
            'down' => self::DOWN,
            default => self::UNKNOWN,
        };
    }

    /**
     * The services the page lists, in inventory order, with how they're
     * labelled. New services show when the page includes them by default.
     *
     * @return list<array{key: string, name: string, label: string, status: string, group: string|null}>
     */
    public function services(): array
    {
        $machine = $this->page->machine;
        $config = $this->page->services ?? [];
        $groups = [];
        foreach ($machine->serviceGroups as $group) {
            foreach ($group->services as $key) {
                $groups[$key] ??= $group->name;
            }
        }

        $inventory = array_filter($machine->inventory['services'] ?? [], fn ($s) => is_array($s) && is_string($s['key'] ?? null) && ! ServiceLabels::isHidden($machine, $s));
        $friendly = self::friendlyNames(array_column(array_map(fn (array $s) => [$s['key'], (string) ($s['name'] ?? $s['key'])], $inventory), 1, 0));

        $rows = [];
        foreach ($inventory as $service) {
            $key = $service['key'];
            $picked = $config[$key]['shown'] ?? null;
            if (! ($picked ?? $this->page->include_new)) {
                continue;
            }
            // Shown only because new services show by default: one that's
            // switched off (stopped or paused) and this page has never seen
            // running stays off the page, rather than showing as down. A
            // crash (failed) still shows, as does one picked for the page.
            if ($picked === null && in_array($service['status'] ?? null, ['stopped', 'paused'], true) && ! $this->everRan($key)) {
                continue;
            }

            $status = $this->statusOf((string) ($service['status'] ?? ''), $key);
            // Only while there's a problem: off the page while it's fine.
            if (($config[$key]['quiet'] ?? false) && $status === self::OPERATIONAL) {
                continue;
            }
            $label = trim((string) ($config[$key]['label'] ?? ''));
            $rows[] = [
                'key' => $key,
                'name' => (string) ($service['name'] ?? $key),
                'label' => $label !== '' ? $label : $friendly[$key],
                'status' => $status,
                'group' => $groups[$key] ?? null,
            ];
        }

        return $rows;
    }

    /** @return Report */
    public function toArray(): array
    {
        $page = $this->page;
        $machine = $page->machine;
        $services = $this->services();
        $days = self::days();

        $components = array_map(fn (array $service) => [
            'key' => $service['key'],
            'label' => $service['label'],
            'status' => $service['status'],
            'group' => $service['group'],
            'uptime' => $page->show_uptime ? $this->uptimeOf($service['key'], $days) : null,
        ], $services);

        $sections = [];
        foreach ($components as $component) {
            $sections[$component['group'] ?? ''][] = $component;
        }
        // Grouped first, in name order; ungrouped last.
        uksort($sections, fn ($a, $b) => [$a === '', $a] <=> [$b === '', $b]);

        // Monitors lead: they're what visitors reach. Ones given a section
        // of their own (another location, say) follow in theirs.
        $monitors = $this->monitors();
        $component = fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['label'],
            'status' => $row['status'],
            'group' => null,
            'responseMs' => $row['status'] === self::OPERATIONAL ? $row['monitor']->last_response_ms : null,
            'uptime' => $page->show_uptime ? self::monitorUptime($row['monitor'], $days) : null,
        ];
        $main = array_values(array_filter($monitors, fn (array $row) => $row['section'] === null));
        $own = [];
        foreach ($monitors as $row) {
            if ($row['section'] !== null) {
                $own[$row['section']][] = $component($row);
            }
        }
        ksort($own, SORT_NATURAL | SORT_FLAG_CASE);
        $monitorComponents = array_map($component, $main);
        if ($monitors !== []) {
            $lead = [];
            if ($monitorComponents !== []) {
                $named = $sections === [] && $own === [] ? null : (collect($main)->every(fn ($r) => $r['monitor']->type === 'http') ? 'Websites' : 'Endpoints');
                $lead[$named ?? ''] = $monitorComponents;
            }
            if (isset($sections['']) && ($lead !== [] || $own !== []) && count($sections) === 1) {
                // The rest, beside the monitors, is the server's services.
                $sections = ['Services' => $sections['']];
            }
            // A monitor section named like a group of services joins it.
            foreach ($own as $name => $items) {
                $sections[$name] = [...$items, ...($sections[$name] ?? [])];
            }
            $sections = [...$lead, ...array_intersect_key($sections, $own), ...array_diff_key($sections, $own)];
            $components = [...$monitorComponents, ...array_merge([], ...array_values($own)), ...$components];
        }

        $percents = array_values(array_filter(array_map(fn ($c) => $c['uptime']['percent'] ?? null, $components), fn ($p) => $p !== null));

        return [
            'title' => $page->title ?: $machine->name,
            'description' => $page->description,
            'notice' => match (true) {
                (bool) $page->notice => ['text' => $page->notice, 'kind' => $page->notice_kind ?? 'info'],
                $machine->inMaintenance() && $machine->maintenance_public => [
                    'text' => ($machine->maintenance_reason ? rtrim($machine->maintenance_reason, '.').'. ' : 'Planned maintenance. ')
                        .'Expected to finish by '.$machine->maintenance_until->format('H:i').' UTC.',
                    'kind' => 'maintenance',
                    // For the page to say in the visitor's own time.
                    'reason' => $machine->maintenance_reason,
                    'until' => $machine->maintenance_until->toIso8601String(),
                ],
                default => null,
            },
            'overall' => self::withNotices($this->overall(array_column($services, 'status'), array_column($monitors, 'status')), $notices = self::notices(StatusIncident::query()->where('machine_id', $machine->id))),
            'server' => $machine->isOnline() ? self::OPERATIONAL : self::UNKNOWN,
            'sections' => array_map(fn ($name, $items) => ['name' => $name === '' ? null : $name, 'components' => $items], array_keys($sections), array_values($sections)),
            'uptimePercent' => $percents === [] ? null : round(array_sum($percents) / count($percents), 2),
            'days' => $page->show_uptime ? $days : [],
            'updatedAt' => ($machine->inventory_scanned_at ?? $machine->last_seen_at)?->toIso8601String(),
            'checkedAt' => $machine->last_seen_at?->toIso8601String(),
            'incidents' => $monitors === [] ? null : self::incidents(array_column(array_map(fn ($r) => [$r['monitor']->id, $r['label']], $monitors), 1, 0)),
            'upcoming' => self::upcoming([$machine]),
            'notices' => $notices,
        ];
    }

    /**
     * Incidents written for the page: open ones first, then ones resolved
     * in the last two weeks, newest first, each with its updates.
     *
     * @param  Builder<StatusIncident>  $query
     * @return list<Notice>
     */
    public static function notices(Builder $query, bool $withMachine = false): array
    {
        return array_values($query->shown()
            ->with(['updates', 'machine'])
            ->orderByRaw('resolved_at is null desc')
            ->latest('started_at')
            ->limit(10)
            ->get()
            ->map(fn (StatusIncident $incident) => $incident->toReport($withMachine))
            ->all());
    }

    /**
     * The headline, made worse by any open incident that's worse than what
     * the checks see: someone saying "major outage" outranks green checks.
     *
     * @param  array{status: string, label: string}  $overall
     * @param  list<Notice>  $notices
     * @return array{status: string, label: string}
     */
    public static function withNotices(array $overall, array $notices): array
    {
        $rank = ['All systems operational' => 0, 'Degraded performance' => 1, 'Partial outage' => 2, 'Major outage' => 3];
        foreach ($notices as $notice) {
            if ($notice['resolvedAt'] !== null) {
                continue;
            }
            $said = match ($notice['impact']) {
                'major' => ['status' => self::DOWN, 'label' => 'Major outage'],
                'partial' => ['status' => self::DOWN, 'label' => 'Partial outage'],
                default => ['status' => self::DEGRADED, 'label' => 'Degraded performance'],
            };
            if ($rank[$said['label']] > ($rank[$overall['label']] ?? 0)) {
                $overall = $said;
            }
        }

        return $overall;
    }

    /**
     * Public maintenance planned on these machines in the next two weeks,
     * soonest first. With more than one machine, each says which.
     *
     * @param  list<Machine>  $machines
     * @return list<array{startsAt: string, endsAt: string, reason: string|null, machine: string|null}>
     */
    public static function upcoming(array $machines): array
    {
        $names = collect($machines)->mapWithKeys(fn (Machine $m) => [$m->id => $m->name]);

        return array_values(MaintenanceWindow::query()
            ->whereIn('machine_id', $names->keys())
            ->where('public', true)
            ->upcoming()
            ->limit(10)
            ->get()
            ->map(fn (MaintenanceWindow $w) => [
                'startsAt' => $w->starts_at->toIso8601String(),
                'endsAt' => $w->ends_at->toIso8601String(),
                'reason' => $w->reason,
                'machine' => count($machines) > 1 ? $names[$w->machine_id] : null,
            ])
            ->all());
    }

    /**
     * Downtime of these monitors over the last two weeks, newest first.
     *
     * @param  array<int, string>  $labels  what each monitor is called on the page, by id
     * @return list<array{label: string, startedAt: string, resolvedAt: string|null, seconds: int}>
     */
    public static function incidents(array $labels): array
    {
        return array_values(MonitorIncident::query()
            ->whereIn('monitor_id', array_keys($labels))
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('started_at', '>=', now()->subDays(self::INCIDENT_DAYS)))
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (MonitorIncident $incident) => [
                'label' => $labels[$incident->monitor_id],
                'startedAt' => $incident->started_at->toIso8601String(),
                'resolvedAt' => $incident->resolved_at?->toIso8601String(),
                'seconds' => (int) $incident->started_at->diffInSeconds($incident->resolved_at ?? now()),
            ])
            ->all());
    }

    /**
     * A monitor's uptime on the page's days, from its daily counts.
     *
     * @param  list<string>  $days
     * @return array{percent: float|null, days: list<float|null>}
     */
    public static function monitorUptime(Monitor $monitor, array $days): array
    {
        $byDay = array_column($monitor->days(StatusPage::UPTIME_DAYS), 'percent', 'day');

        return [
            'percent' => $monitor->uptime(StatusPage::UPTIME_DAYS),
            'days' => array_map(fn (string $day) => $byDay[$day] ?? null, $days),
        ];
    }

    /**
     * One headline for the whole page.
     *
     * Monitors are checked from outside, so they still count when the
     * server itself has stopped reporting.
     *
     * @param  list<string>  $statuses  the services'
     * @param  list<string>  $monitors  the monitors'
     * @return array{status: string, label: string}
     */
    public function overall(array $statuses, array $monitors = []): array
    {
        $monitors = array_values(array_filter($monitors, fn ($s) => $s !== self::UNKNOWN));

        $machine = $this->page->machine;
        if ($machine->inMaintenance() && $machine->maintenance_public) {
            $troubled = ! $machine->isOnline() || array_diff([...$statuses, ...$monitors], [self::OPERATIONAL]) !== [];
            if ($troubled) {
                return ['status' => self::DEGRADED, 'label' => 'Under maintenance'];
            }
        }

        if (! $this->page->machine->isOnline()) {
            if ($monitors === []) {
                return ['status' => self::UNKNOWN, 'label' => 'Status unknown: the server isn’t reporting'];
            }
            $statuses = [];
        }
        $statuses = [...$statuses, ...$monitors];

        $down = count(array_filter($statuses, fn ($s) => $s === self::DOWN));
        $degraded = count(array_filter($statuses, fn ($s) => $s === self::DEGRADED));

        return match (true) {
            $statuses === [] => ['status' => self::OPERATIONAL, 'label' => 'All systems operational'],
            $down === count($statuses) => ['status' => self::DOWN, 'label' => 'Major outage'],
            $down > 0 => ['status' => self::DOWN, 'label' => 'Partial outage'],
            $degraded > 0 => ['status' => self::DEGRADED, 'label' => 'Degraded performance'],
            default => ['status' => self::OPERATIONAL, 'label' => 'All systems operational'],
        };
    }

    /**
     * "serveros-umami-umami-1" → "Umami"; "nginx.service" → "Nginx"; a
     * deployed app's "serveros-shop-1a2b3c4d5e6f" → "Shop".
     */
    public static function friendlyName(string $name): string
    {
        $name = AppReleases::parse($name)['app'] ?? $name;
        $name = preg_replace('/^serveros-/', '', $name) ?? $name;
        $name = preg_replace('/\.service$/', '', $name) ?? $name;
        $name = preg_replace('/-\d+$/', '', $name) ?? $name;

        // A Compose container repeats its project: umami-umami → umami,
        // minecraft-java-minecraft → minecraft-java.
        $parts = explode('-', $name);
        if (count($parts) > 1 && in_array(end($parts), array_slice($parts, 0, -1), true)) {
            array_pop($parts);
        }

        return ucwords(str_replace(['-', '_'], ' ', implode('-', $parts)));
    }

    /**
     * Friendly names for a machine's services, keeping the number where
     * it's all that tells them apart: eu-west-node-1 and eu-west-node-2 →
     * "Eu West Node 1" and "Eu West Node 2", not two "Eu West Node"s.
     *
     * @param  array<string, string>  $names  service key → name
     * @return array<string, string> service key → friendly name
     */
    public static function friendlyNames(array $names): array
    {
        $friendly = array_map(self::friendlyName(...), $names);
        $counts = array_count_values($friendly);
        foreach ($friendly as $key => $label) {
            $name = preg_replace('/\.service$/', '', $names[$key]) ?? $names[$key];
            if ($counts[$label] > 1 && preg_match('/-(\d+)$/', $name, $match)) {
                $friendly[$key] = $label.' '.$match[1];
            }
        }

        return $friendly;
    }

    /** How recent a running report must be to beat the last scan. */
    public const FRESH_SECONDS = 180;

    /**
     * A service's status from the last scan, unless the machine's
     * minute-by-minute report says it's running now: a scan can be hours
     * old and land in the seconds a planned restart takes (a queue worker's
     * hourly --max-time), which isn't an outage.
     */
    /** Whether this page has seen the service running on any day it keeps. */
    private function everRan(string $key): bool
    {
        foreach ($this->page->uptime ?? [] as $day) {
            if (($day['services'][$key][0] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function statusOf(string $status, ?string $key = null): string
    {
        $machine = $this->page->machine;
        if (! $machine->isOnline()) {
            return self::UNKNOWN;
        }

        // Crash-looping shows up running between crashes: that's not fine.
        $looping = $key !== null && isset($machine->alert_state['services']['looping'][$key]);
        $reported = $key === null ? null : ($machine->service_stats[$key]['at'] ?? null);
        if ($looping) {
            return self::DEGRADED;
        }
        if (is_numeric($reported) && (int) $reported >= now()->getTimestamp() - self::FRESH_SECONDS) {
            return self::OPERATIONAL;
        }

        return match ($status) {
            'running' => self::OPERATIONAL,
            'restarting', 'degraded' => self::DEGRADED,
            default => self::DOWN,
        };
    }

    /**
     * The days the bars cover, oldest first, ending today.
     *
     * @return list<string>
     */
    public static function days(): array
    {
        $today = now()->startOfDay();

        return array_map(
            fn (int $ago) => $today->copy()->subDays($ago)->toDateString(),
            range(StatusPage::UPTIME_DAYS - 1, 0),
        );
    }

    /**
     * A service's uptime per day: the share of the day's snapshots it was
     * running in. Before it was first seen there's no data (null), and on
     * that first day it counts from the snapshot it appeared in. Days with
     * no snapshots at all are null too: no data, not down.
     *
     * @param  list<string>  $days
     * @return array{percent: float|null, days: list<float|null>}
     */
    private function uptimeOf(string $key, array $days): array
    {
        $uptime = $this->page->uptime ?? [];
        $values = [];
        $seen = 0;
        $expected = 0;
        $known = false;

        foreach ($days as $day) {
            $record = $uptime[$day] ?? null;
            $batches = (int) ($record['batches'] ?? 0);
            $entry = $record['services'][$key] ?? null;

            if ($batches === 0 || (! $known && $entry === null)) {
                $values[] = null;

                continue;
            }

            [$hits, $first] = $entry ?? [0, 1];
            $possible = $known ? $batches : max(1, $batches - $first + 1);
            $known = true;

            $values[] = round(min(1, $hits / $possible) * 100, 2);
            $seen += $hits;
            $expected += $possible;
        }

        return ['percent' => $expected === 0 ? null : round(min(1, $seen / $expected) * 100, 2), 'days' => $values];
    }
}
