<?php

namespace App\Support\StatusPages;

use App\Models\Machine;
use App\Models\Monitor;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\WorkspaceStatusPage;

/**
 * What a workspace's fleet status page shows: the uptime monitors it picks
 * that aren't on any machine (a site hosted elsewhere) first, then one
 * section per machine it covers, built from that machine's own StatusReport (so the services,
 * labels and monitors are whatever its status page settings pick), and one
 * headline for all of them. Same shape as StatusReport::toArray(), so the
 * same page renders it.
 *
 * @phpstan-import-type Report from StatusReport
 * @phpstan-import-type Component from StatusReport
 */
final class FleetStatusReport
{
    public function __construct(private readonly WorkspaceStatusPage $page) {}

    /** @return Report */
    public function toArray(): array
    {
        $page = $this->page;
        $sections = [];
        $overalls = [];
        $percents = [];
        $incidents = [];
        $days = [];
        $maintenance = [];
        $updated = null;
        $checked = null;
        $online = false;

        $machines = $page->coveredMachines();
        // One machine: its page's own sections, as they are. A machine's name
        // means nothing to the people reading the page.
        $single = count($machines) === 1;

        foreach ($machines as $machine) {
            $report = (new StatusReport(self::pageFor($machine, $page->show_uptime, $page->new_machines === 'services')))->toArray();

            if ($single) {
                foreach ($report['sections'] as $section) {
                    $sections[] = [...$section, 'components' => array_map(fn (array $c) => [...$c, 'key' => "{$machine->id}:{$c['key']}"], $section['components'])];
                }
            }

            // Nothing picked on this machine: it still shows, as one row for
            // the server itself, rather than dropping off the page.
            if (array_merge(...array_column($report['sections'], 'components')) === []) {
                $report['sections'] = [['name' => null, 'components' => [[
                    'key' => 'machine',
                    // Under the machine's own heading when the page has several.
                    'label' => $single ? $machine->name : 'Server',
                    'status' => $report['server'],
                    'group' => null,
                    'uptime' => null,
                ]]]];
                if ($single) {
                    $sections[] = ['name' => null, 'components' => [[...$report['sections'][0]['components'][0], 'key' => "{$machine->id}:machine"]]];
                }
            }

            $components = [];
            foreach ($report['sections'] as $section) {
                foreach ($section['components'] as $component) {
                    $components[] = [
                        ...$component,
                        // Unique across machines, and grouped under the machine.
                        'key' => "{$machine->id}:{$component['key']}",
                        'label' => $section['name'] !== null && $section['name'] !== 'Services'
                            ? "{$component['label']} ({$section['name']})"
                            : $component['label'],
                        'group' => $machine->name,
                    ];
                }
            }
            if ($components !== [] && ! $single) {
                $sections[] = ['name' => $machine->name, 'components' => $components, 'machine' => $machine->id];
            }

            $overalls[] = [...$report['overall'], 'components' => array_column($components, 'status')];
            if ($report['uptimePercent'] !== null) {
                $percents[] = $report['uptimePercent'];
            }
            array_push($incidents, ...array_map(fn ($i) => $single ? $i : [...$i, 'label' => "{$i['label']} ({$machine->name})"], $report['incidents'] ?? []));
            $days = $days ?: $report['days'];
            $updated = max($updated, $report['updatedAt']);
            $checked = max($checked, $report['checkedAt']);
            $online = $online || $machine->isOnline();
            if ($machine->inMaintenance() && $machine->maintenance_public) {
                $maintenance[] = $machine->name;
            }
        }

        $sections = self::arrange($sections, $page->layout ?? []);

        // Monitors on no machine lead, like monitors do on a machine's page:
        // they're what visitors reach. Checked from outside, so they count
        // whether or not any server reports.
        $monitors = $page->standaloneMonitors();
        if ($monitors->isNotEmpty()) {
            $days = $days ?: StatusReport::days();
            $components = array_values($monitors->map(fn (Monitor $monitor) => [
                'key' => 'monitor:'.$monitor->id,
                'label' => $monitor->name,
                'status' => $status = StatusReport::monitorStatus($monitor),
                'group' => null,
                'responseMs' => $status === StatusReport::OPERATIONAL ? $monitor->last_response_ms : null,
                'uptime' => $page->show_uptime ? StatusReport::monitorUptime($monitor, $days) : null,
            ])->all());
            $named = $monitors->every(fn (Monitor $m) => $m->type === 'http') ? 'Websites' : 'Endpoints';
            array_unshift($sections, ['name' => $sections === [] ? null : $named, 'components' => $components]);
            $overalls[] = ['status' => StatusReport::OPERATIONAL, 'label' => '', 'components' => array_column($components, 'status')];
            array_push($percents, ...array_values(array_filter(array_map(fn ($c) => $c['uptime']['percent'] ?? null, $components), fn ($p) => $p !== null)));
            array_push($incidents, ...StatusReport::incidents($monitors->mapWithKeys(fn (Monitor $m) => [$m->id => $m->name])->all()));
            $checked = max($checked, $monitors->max(fn (Monitor $m) => $m->last_checked_at?->toIso8601String()));
            $updated ??= $checked;
            $online = true;
        }

        usort($incidents, fn ($a, $b) => strcmp($b['startedAt'], $a['startedAt']));

        // Written incidents: the workspace's own, and those of the machines it covers.
        $notices = StatusReport::notices(
            StatusIncident::query()->where('workspace_id', $page->workspace_id)
                ->where(fn ($q) => $q->whereNull('machine_id')->orWhereIn('machine_id', $machines->modelKeys())),
            withMachine: count($machines) > 1,
        );

        return [
            'title' => $page->title ?: $page->workspace->name,
            'description' => $page->description,
            'notice' => match (true) {
                (bool) $page->notice => ['text' => $page->notice, 'kind' => $page->notice_kind ?? 'info'],
                $maintenance !== [] => ['text' => 'Planned maintenance on '.implode(', ', $maintenance).'.', 'kind' => 'maintenance'],
                default => null,
            },
            'overall' => StatusReport::withNotices(self::overall($overalls), $notices),
            'server' => $online ? StatusReport::OPERATIONAL : StatusReport::UNKNOWN,
            'sections' => $sections,
            'uptimePercent' => $percents === [] ? null : round(array_sum($percents) / count($percents), 2),
            'days' => $page->show_uptime ? $days : [],
            'updatedAt' => $updated,
            'checkedAt' => $checked,
            'incidents' => $incidents === [] ? null : array_slice($incidents, 0, 20),
            'upcoming' => StatusReport::upcoming(array_values($machines->all())),
            'notices' => $notices,
        ];
    }

    /**
     * The machine's own page settings or, when it has none, one row for it
     * (or its running services, if the fleet page shows new machines that
     * way). Uptime follows the fleet page's setting.
     */
    public static function pageFor(Machine $machine, bool $showUptime, bool $newServices = false): StatusPage
    {
        // A machine nobody has set a page up for shows as itself, or with
        // its running services when the fleet page says so.
        $page = $machine->statusPage?->replicate() ?? new StatusPage(['include_new' => $newServices, 'services' => []]);
        $page->show_uptime = $showUptime;
        $page->uptime = $machine->statusPage?->uptime;
        $page->setRelation('machine', $machine);

        return $page;
    }

    /**
     * Everything the page can show, machine by machine, for arranging it:
     * each component's key as the layout names it, its label and machine.
     *
     * @return list<array{key: string, label: string, machine: string, machineId: int}>
     */
    public static function components(WorkspaceStatusPage $page): array
    {
        $rows = [];
        foreach ($page->coveredMachines() as $machine) {
            $report = (new StatusReport(self::pageFor($machine, false, $page->new_machines === 'services')))->toArray();
            $components = array_merge(...array_column($report['sections'], 'components'));
            if ($components === []) {
                $components = [['key' => 'machine', 'label' => 'Server']];
            }
            foreach ($components as $component) {
                $rows[] = ['key' => "{$machine->id}:{$component['key']}", 'label' => $component['label'], 'machine' => $machine->name, 'machineId' => $machine->id];
            }
        }

        return $rows;
    }

    /**
     * The page's own layout over the machines' sections: sections of its own
     * first, each pulling components from any machine in the order listed;
     * then the machines' sections, renamed and in the order it gives (the
     * rest by name). Components moved into a section of its own leave their
     * machine's, and a machine left with nothing drops its heading.
     *
     * @param  list<array{name: string|null, components: list<Component>, machine?: int}>  $sections
     * @param  array{sections?: list<array{name: string, components: list<string>}>, headings?: array<int|string, string>, order?: list<int>}  $layout
     * @return list<array{name: string|null, components: list<Component>}>
     */
    public static function arrange(array $sections, array $layout): array
    {
        $own = [];
        $taken = [];
        $all = [];
        foreach ($sections as $section) {
            foreach ($section['components'] as $component) {
                $all[$component['key']] = $component;
            }
        }

        foreach ($layout['sections'] ?? [] as $custom) {
            $name = trim($custom['name']);
            $components = [];
            foreach ($custom['components'] as $key) {
                if (isset($all[$key]) && ! isset($taken[$key])) {
                    $taken[$key] = true;
                    $components[] = [...$all[$key], 'group' => $name !== '' ? $name : null];
                }
            }
            // The same name from two machines: say which is which.
            $counts = array_count_values(array_column($components, 'label'));
            foreach ($components as $i => $component) {
                if ($counts[$component['label']] > 1 && isset($all[$component['key']]['group'])) {
                    $components[$i]['label'] = "{$component['label']} ({$all[$component['key']]['group']})";
                }
            }
            if ($components !== []) {
                $own[] = ['name' => $name !== '' ? $name : null, 'components' => $components];
            }
        }

        $headings = $layout['headings'] ?? [];
        $order = array_flip(array_map('intval', $layout['order'] ?? []));
        $rest = [];
        foreach ($sections as $index => $section) {
            $components = array_values(array_filter($section['components'], fn (array $c) => ! isset($taken[$c['key']])));
            if ($components === []) {
                continue;
            }
            $machine = $section['machine'] ?? null;
            $heading = $machine !== null ? trim((string) ($headings[(string) $machine] ?? '')) : '';
            if ($heading !== '') {
                $components = array_map(fn (array $c) => [...$c, 'group' => $heading], $components);
            }
            $rest[] = [
                'name' => $heading !== '' ? $heading : $section['name'],
                'components' => $components,
                'rank' => $machine !== null && isset($order[$machine]) ? $order[$machine] : count($order) + $index,
            ];
        }
        usort($rest, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return [...$own, ...array_map(fn (array $s) => ['name' => $s['name'], 'components' => $s['components']], $rest)];
    }

    /**
     * One headline for the fleet, from every service shown (not from each
     * machine's headline: one service down on one machine is a partial
     * outage, not a major one). Machines that aren't reporting count as
     * unknown.
     *
     * @param  list<array{status: string, label: string, components: list<string>}>  $machines
     * @return array{status: string, label: string}
     */
    public static function overall(array $machines): array
    {
        $reporting = array_values(array_filter($machines, fn ($m) => $m['status'] !== StatusReport::UNKNOWN));
        $statuses = array_merge([], ...array_column($reporting, 'components'));
        $statuses = array_values(array_filter($statuses, fn ($s) => $s !== StatusReport::UNKNOWN));
        $down = count(array_filter($statuses, fn ($s) => $s === StatusReport::DOWN));
        $maintenance = in_array('Under maintenance', array_column($machines, 'label'), true);

        return match (true) {
            $machines === [] => ['status' => StatusReport::OPERATIONAL, 'label' => 'All systems operational'],
            $reporting === [] => ['status' => StatusReport::UNKNOWN, 'label' => 'Status unknown: the servers aren’t reporting'],
            $statuses !== [] && $down === count($statuses) => ['status' => StatusReport::DOWN, 'label' => 'Major outage'],
            $down > 0 => ['status' => StatusReport::DOWN, 'label' => 'Partial outage'],
            $maintenance => ['status' => StatusReport::DEGRADED, 'label' => 'Under maintenance'],
            in_array(StatusReport::DEGRADED, $statuses, true) => ['status' => StatusReport::DEGRADED, 'label' => 'Degraded performance'],
            count($reporting) < count($machines) => ['status' => StatusReport::DEGRADED, 'label' => 'Some servers aren’t reporting'],
            default => ['status' => StatusReport::OPERATIONAL, 'label' => 'All systems operational'],
        };
    }
}
