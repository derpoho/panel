<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\ServiceGroup;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Audit\AuditContext;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\StatusPages\StatusReport;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * What the status page tools share: a machine's page (or the draft its
 * settings start from), the fleet page, their addresses, the rows the
 * settings list, the checks a save goes through, and a preview compact
 * enough for an agent to read.
 */
final class StatusPages
{
    /** The settings pages' words for a bad address. */
    public const MESSAGES = [
        'slug.regex' => 'Use lowercase letters, numbers and single dashes.',
        'slug.unique' => 'That address is taken.',
    ];

    /** How status/Show.vue ranks a section by its worst component. */
    private const RANK = [StatusReport::OPERATIONAL => 0, StatusReport::UNKNOWN => 1, StatusReport::DEGRADED => 2, StatusReport::DOWN => 3];

    /** An id or a name, as a validation rule. */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }

    /**
     * The machine's page, or an unsaved draft with a free address when it
     * has none (as its settings show), with the machine, its groups and the
     * workspace loaded fresh.
     */
    public static function machinePage(McpContext $context, Machine $machine): StatusPage
    {
        $machine->setRelation('workspace', $context->workspace);
        $machine->load(['statusPage', 'serviceGroups']);

        $page = $machine->statusPage ?? StatusPage::draftFor($machine);
        $page->setRelation('machine', $machine);

        return $page;
    }

    /**
     * The workspace's fleet page, or an unsaved draft, as
     * WorkspaceStatusPageController makes one.
     */
    public static function fleetPage(McpContext $context): WorkspaceStatusPage
    {
        $workspace = $context->workspace;
        $page = WorkspaceStatusPage::query()->where('workspace_id', $workspace->id)->first();

        if ($page === null) {
            $base = Str::slug($workspace->name) ?: 'status';
            $slug = $base;
            while (WorkspaceStatusPage::query()->where('slug', $slug)->exists() || StatusPage::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.Str::lower(Str::random(4));
            }
            $page = new WorkspaceStatusPage(['slug' => $slug, 'enabled' => false, 'public' => true, 'show_uptime' => true]);
        }
        $page->setRelation('workspace', $workspace);

        return $page;
    }

    /**
     * Where a page is served, on the main domain (not the workspace's).
     *
     * @return array{url: string, badgeUrl: string, jsonUrl: string}
     */
    public static function addresses(string $slug): array
    {
        $main = rtrim((string) config('app.url'), '/');

        return [
            'url' => $main.route('status.show', $slug, false),
            'badgeUrl' => $main.route('status.badge', $slug, false),
            'jsonUrl' => $main.route('status.json', $slug, false),
            'feedUrl' => $main.route('status.feed', $slug, false),
        ];
    }

    /**
     * A machine page's own settings, as its settings page shows them.
     *
     * @return array<string, mixed>
     */
    public static function settings(StatusPage $page): array
    {
        return [
            'exists' => $page->exists,
            'enabled' => (bool) $page->enabled,
            'live' => $page->exists && $page->live(),
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'notice' => $page->notice,
            'noticeKind' => $page->notice ? ($page->notice_kind ?? 'info') : null,
            'includeNew' => (bool) $page->include_new,
            'showUptime' => (bool) $page->show_uptime,
            // People who confirmed email updates from it.
            'subscribers' => $page->exists ? $page->subscribers()->whereNotNull('confirmed_at')->count() : 0,
            ...self::addresses($page->slug),
        ];
    }

    /**
     * The fleet page's settings, as the workspace settings show them.
     *
     * @return array<string, mixed>
     */
    public static function fleetSettings(WorkspaceStatusPage $page): array
    {
        return [
            'exists' => $page->exists,
            'enabled' => (bool) $page->enabled,
            'live' => $page->exists && $page->live(),
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'notice' => $page->notice,
            'noticeKind' => $page->notice ? ($page->notice_kind ?? 'info') : null,
            'showUptime' => (bool) $page->show_uptime,
            // People who confirmed email updates from it.
            'subscribers' => $page->exists ? $page->subscribers()->whereNotNull('confirmed_at')->count() : 0,
            'everyMachine' => $page->machines === null,
            'machines' => $page->machines,
            'machineGroups' => $page->machine_groups ?? [],
            'newMachines' => $page->new_machines ?? 'machine',
            'layout' => $page->layout,
            'monitors' => $page->monitors ?? [],
            ...self::addresses($page->slug),
        ];
    }

    /**
     * The services from the machine's latest scan, as the status page
     * reads them (every entry, retired app versions included).
     *
     * @return list<array<string, mixed>>
     */
    public static function inventory(Machine $machine): array
    {
        return array_values(array_filter($machine->inventory['services'] ?? [], fn ($s) => is_array($s) && is_string($s['key'] ?? null)));
    }

    /**
     * Which section (service group) each inventory key is in, the way
     * StatusReport works it out: the first group that lists it.
     *
     * @return array<string, array{id: int, name: string}>
     */
    public static function sectionOf(Machine $machine): array
    {
        $sections = [];
        foreach ($machine->serviceGroups as $group) {
            foreach ($group->services as $key) {
                $sections[$key] ??= ['id' => $group->id, 'name' => $group->name];
            }
        }

        return $sections;
    }

    /**
     * Every service on the machine and how the page treats it.
     *
     * @return list<array<string, mixed>>
     */
    public static function serviceRows(StatusPage $page): array
    {
        $machine = $page->machine;
        $config = $page->services ?? [];
        $sections = self::sectionOf($machine);
        $live = AppReleases::live($machine);

        return array_map(function (array $service) use ($machine, $config, $sections, $live, $page) {
            $key = $service['key'];
            $name = (string) ($service['name'] ?? $key);
            $release = AppReleases::parse($name);

            return [
                'key' => $key,
                'name' => $name,
                'panelName' => ServiceLabels::display($machine, $service),
                'app' => $release !== null && isset($live[$release['app']]) ? $release['app'] : null,
                'retired' => AppReleases::retired($machine, $service),
                'kind' => $service['kind'] ?? 'other',
                'image' => $service['details']['image'] ?? null,
                'status' => $service['status'] ?? 'unknown',
                'shown' => (bool) ($config[$key]['shown'] ?? $page->include_new),
                'label' => $config[$key]['label'] ?? null,
                'suggested' => StatusReport::friendlyName($name),
                'section' => $sections[$key]['name'] ?? null,
            ];
        }, self::inventory($machine));
    }

    /**
     * The machine's monitors (paused ones aren't on the page) and how the
     * page treats them.
     *
     * @return list<array<string, mixed>>
     */
    public static function monitorRows(StatusPage $page, StatusReport $report): array
    {
        $config = $page->services ?? [];

        return array_values($report->allMonitors()->map(fn (Monitor $monitor) => [
            'key' => 'monitor:'.$monitor->id,
            'id' => $monitor->id,
            'name' => $monitor->name,
            'type' => $monitor->type,
            'target' => $monitor->target(),
            'status' => $monitor->status,
            'shown' => (bool) ($config['monitor:'.$monitor->id]['shown'] ?? $page->include_new),
            'label' => $config['monitor:'.$monitor->id]['label'] ?? null,
            // A section of its own on the page, when it has one.
            'section' => $config['monitor:'.$monitor->id]['section'] ?? null,
        ])->all());
    }

    /**
     * The machine's service groups, which are the page's sections, in the
     * order the page shows them (by name).
     *
     * @return list<array<string, mixed>>
     */
    public static function groups(Machine $machine, StatusReport $report): array
    {
        $sections = self::sectionOf($machine);
        $shown = array_column($report->services(), 'key');

        return array_values($machine->serviceGroups
            ->sort(fn (ServiceGroup $a, ServiceGroup $b) => $a->name <=> $b->name)
            ->map(fn (ServiceGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'services' => $group->services,
                // What the page lists under it: shown, and not claimed by an earlier group.
                'onPage' => array_values(array_filter($shown, fn (string $key) => ($sections[$key]['id'] ?? null) === $group->id)),
                'iconUrl' => $group->icon_path === null ? null : Storage::disk('public')->url($group->icon_path),
            ])->all());
    }

    /**
     * A status report (StatusReport or FleetStatusReport::toArray()) made
     * short: each section with its worst status and whether it starts
     * folded, each component's status and uptime, and the day-by-day bars
     * only when asked for.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function preview(array $report, bool $days = false): array
    {
        $sections = $report['sections'] ?? [];
        // status/Show.vue folds long pages: sections with nothing wrong start closed.
        $folds = count($sections) > 2 && array_sum(array_map(fn ($s) => count($s['components']), $sections)) > 8;

        $preview = [
            'title' => $report['title'],
            'description' => $report['description'],
            'notice' => $report['notice'],
            'overall' => $report['overall'],
            'server' => $report['server'],
            'uptimePercent' => $report['uptimePercent'],
            'sectionsFold' => $folds,
            'sections' => array_map(function (array $section) use ($folds, $days) {
                $worst = self::worst(array_column($section['components'], 'status'));

                return [
                    'name' => $section['name'],
                    'status' => $worst,
                    'startsFolded' => $folds && $worst === StatusReport::OPERATIONAL,
                    'components' => array_map(fn (array $c) => array_filter([
                        'key' => $c['key'],
                        'label' => $c['label'],
                        'status' => $c['status'],
                        'uptimePercent' => $c['uptime']['percent'] ?? null,
                        'responseMs' => $c['responseMs'] ?? null,
                        'uptimeDays' => $days ? ($c['uptime']['days'] ?? null) : null,
                    ], fn ($v) => $v !== null), $section['components']),
                ];
            }, $sections),
            'incidents' => $report['incidents'],
            'updatedAt' => $report['updatedAt'],
            'checkedAt' => $report['checkedAt'],
        ];
        if ($days) {
            $preview['days'] = $report['days'];
        }

        return $preview;
    }

    /** @param  list<string>  $statuses */
    public static function worst(array $statuses): string
    {
        return array_reduce($statuses, fn (string $acc, string $s) => (self::RANK[$s] ?? 0) > self::RANK[$acc] ? $s : $acc, StatusReport::OPERATIONAL);
    }

    /**
     * Something a machine page can list, by reference: a monitor as
     * monitor:<id> or monitor:<name>, otherwise a service by its key from
     * the latest scan or anything McpContext::service() takes (name,
     * label, app:<name>).
     *
     * @return array{key: string, monitor: bool, name: string, aliases: list<string>}
     */
    public static function resolve(McpContext $context, Machine $machine, string $reference): array
    {
        $reference = trim($reference);

        if (str_starts_with(mb_strtolower($reference), 'monitor:')) {
            $which = trim(substr($reference, 8));
            $monitors = Monitor::query()->where('machine_id', $machine->id)->where('workspace_id', $context->workspace->id);
            $monitor = ctype_digit($which) ? (clone $monitors)->find((int) $which) : null;
            if ($monitor === null && $which !== '') {
                $named = (clone $monitors)->whereRaw('lower(name) = ?', [mb_strtolower($which)])->get();
                if ($named->count() > 1) {
                    throw new ToolError("Several monitors on {$machine->name} are called “{$which}”: ".$named->map(fn (Monitor $m) => 'monitor:'.$m->id)->implode(', ').'. Use the id.');
                }
                $monitor = $named->first();
            }
            if ($monitor === null) {
                throw new ToolError("No monitor “{$reference}” on {$machine->name}. get_status_page lists its monitors (keys like monitor:12).");
            }

            return ['key' => 'monitor:'.$monitor->id, 'monitor' => true, 'name' => $monitor->name, 'aliases' => []];
        }

        $service = collect(self::inventory($machine))->firstWhere('key', $reference) ?? $context->service($machine, $reference);
        $key = (string) $service['key'];
        $release = AppReleases::parse($service['name'] ?? null);
        $app = $release !== null && isset(AppReleases::live($machine)[$release['app']]) && ! AppReleases::retired($machine, $service) ? $release['app'] : null;

        return [
            'key' => $key,
            'monitor' => false,
            'name' => (string) ($service['name'] ?? $key),
            // The dashboard files a deployed app in groups by its stable name.
            'aliases' => $app !== null ? ['app:'.$app] : [],
        ];
    }

    /**
     * The page's settings for each service and monitor, ready to change:
     * as stored, less things no longer on the machine (a save from its
     * settings drops those too; only after a complete scan, so a partial
     * one loses nothing). When include_new is about to change, the ones
     * there now are pinned to how they show, as a save from the settings
     * does, so only services found later follow the new setting.
     *
     * @return array<string, array{shown: bool, label: string|null, quiet?: bool, section?: string}>
     */
    public static function config(StatusPage $page, ?bool $includeNew = null): array
    {
        $machine = $page->machine;
        $current = [
            ...array_column(self::inventory($machine), 'key'),
            // Paused monitors too: they keep their place for when they're back.
            ...Monitor::query()->where('machine_id', $machine->id)->pluck('id')->map(fn ($id) => 'monitor:'.$id)->all(),
        ];

        $config = [];
        foreach ($page->services ?? [] as $key => $entry) {
            if (isset($entry['shown'])) {
                $config[(string) $key] = ['shown' => (bool) $entry['shown'], 'label' => $entry['label'] ?? null];
                if ($entry['quiet'] ?? false) {
                    $config[(string) $key]['quiet'] = true;
                }
                if (filled($entry['section'] ?? null)) {
                    $config[(string) $key]['section'] = (string) $entry['section'];
                }
            }
        }
        if (($machine->inventory['complete'] ?? false) === true) {
            $config = array_intersect_key($config, array_flip($current));
        }
        if ($includeNew !== null && $includeNew !== (bool) $page->include_new) {
            foreach ($current as $key) {
                $config[$key] ??= ['shown' => (bool) $page->include_new, 'label' => null];
            }
        }

        return $config;
    }

    /**
     * Check a machine page's complete settings with the same rules as
     * MachineSettingsController@updateStatusPage, plan included.
     *
     * @param  array<string, mixed>  $payload  enabled, public, slug, title, description, include_new, show_uptime, notice, notice_kind, services
     * @return array<string, mixed>
     */
    public static function validateMachinePage(Workspace $workspace, StatusPage $page, array $payload): array
    {
        $validator = Validator::make($payload, [
            'enabled' => ['required', 'boolean'],
            'public' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('status_pages', 'slug')->ignore($page->id), Rule::unique('workspace_status_pages', 'slug')],
            'title' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:280'],
            'include_new' => ['required', 'boolean'],
            'show_uptime' => ['required', 'boolean'],
            'notice' => ['nullable', 'string', 'max:500'],
            'notice_kind' => ['nullable', Rule::in(StatusPage::NOTICE_KINDS)],
            'services' => ['present', 'array', 'list', 'max:500'],
            'services.*.key' => ['required', 'string', 'max:300'],
            'services.*.shown' => ['required', 'boolean'],
            'services.*.label' => ['nullable', 'string', 'max:60'],
            'services.*.quiet' => ['sometimes', 'boolean'],
            'services.*.section' => ['sometimes', 'nullable', 'string', 'max:60'],
        ], self::MESSAGES);
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();

        if ($data['enabled'] && ! $workspace->hasStatusPages()) {
            throw new ToolError('Status pages come with Pro. Choose Pro or Business to turn one on.'.($page->enabled ? ' (This page is still switched on from before: set enabled to false to switch it off.)' : ''));
        }

        return $data;
    }

    /**
     * Save a machine page from settings validateMachinePage() passed, as
     * MachineSettingsController@updateStatusPage does, and note it in the
     * activity with the same words. Nothing is saved (or noted) when
     * nothing changes on a page that already exists.
     *
     * @param  array<string, mixed>  $data
     * @return array{created: bool, changed: list<string>}
     */
    public static function saveMachinePage(McpContext $context, Machine $machine, StatusPage $page, array $data): array
    {
        $services = [];
        foreach ($data['services'] as $service) {
            $label = trim((string) ($service['label'] ?? ''));
            $services[$service['key']] = ['shown' => (bool) $service['shown'], 'label' => $label === '' ? null : $label];
            if ($service['shown'] && ($service['quiet'] ?? false)) {
                $services[$service['key']]['quiet'] = true;
            }
            // A monitor's own section: as sent, or as it was.
            $section = array_key_exists('section', $service) ? $service['section'] : ($page->services[$service['key']]['section'] ?? null);
            $section = trim((string) $section);
            if ($section !== '' && str_starts_with($service['key'], 'monitor:')) {
                $services[$service['key']]['section'] = $section;
            }
        }

        $before = self::snapshot($page);
        $page->fill([
            'enabled' => $data['enabled'],
            'public' => $data['public'],
            'slug' => $data['slug'],
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'include_new' => $data['include_new'],
            'show_uptime' => $data['show_uptime'],
            'notice' => filled($data['notice'] ?? null) ? trim($data['notice']) : null,
            'notice_kind' => filled($data['notice'] ?? null) ? ($data['notice_kind'] ?? 'info') : null,
            'services' => $services,
        ]);
        $page->machine_id = $machine->id;

        $after = self::snapshot($page);
        $changed = array_keys(array_filter($after, fn ($value, $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
        $created = ! $page->exists;
        if (! $created && $changed === []) {
            AuditContext::nothingChanged();

            return ['created' => false, 'changed' => []];
        }

        $wasLive = (bool) $page->getOriginal('enabled');
        $page->save();
        $context->record(match (true) {
            $page->enabled && ! $wasLive => 'status_page.publish',
            ! $page->enabled && $wasLive => 'status_page.unpublish',
            default => 'status_page.update',
        }, $page->title ?: $machine->name, $machine);

        return ['created' => $created, 'changed' => $changed];
    }

    /**
     * A page's settings, comparable before and after a change.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(StatusPage $page): array
    {
        $services = collect($page->services ?? [])
            ->map(fn (array $entry) => ['shown' => (bool) ($entry['shown'] ?? false), 'label' => $entry['label'] ?? null, 'quiet' => (bool) ($entry['quiet'] ?? false), 'section' => $entry['section'] ?? null])
            ->sortKeys()
            ->all();

        return [
            'enabled' => (bool) $page->enabled,
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'include_new' => (bool) $page->include_new,
            'show_uptime' => (bool) $page->show_uptime,
            'notice' => $page->notice,
            'notice_kind' => $page->notice ? $page->notice_kind : null,
            'services' => $services,
        ];
    }

    /**
     * The settings payload for a page as it stands, with these changes.
     *
     * @param  array<string, mixed>  $changes  fields to set, by their request names
     * @param  array<string, array{shown: bool, label: string|null, quiet?: bool, section?: string}>  $config
     * @return array<string, mixed>
     */
    public static function payload(StatusPage $page, array $changes, array $config): array
    {
        return [
            'enabled' => (bool) $page->enabled,
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'include_new' => (bool) $page->include_new,
            'show_uptime' => (bool) $page->show_uptime,
            'notice' => $page->notice,
            'notice_kind' => $page->notice_kind,
            ...$changes,
            'services' => array_map(fn (string $key, array $entry) => ['key' => $key, 'shown' => $entry['shown'], 'label' => $entry['label'], 'quiet' => $entry['quiet'] ?? false, 'section' => $entry['section'] ?? null], array_keys($config), array_values($config)),
        ];
    }
}
