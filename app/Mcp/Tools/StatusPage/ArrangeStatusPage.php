<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Support\RemoteImage;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Monitor;
use App\Models\ServiceGroup;
use App\Models\StatusPage;
use App\Support\StatusPages\StatusReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Organise a machine's status page in one go: its sections (the machine's
 * service groups, saved as the dashboard saves them), which services and
 * monitors show, and what they're called.
 */
final class ArrangeStatusPage extends Tool
{
    /** Icons fetched in one call, at most: each is a download. */
    private const MAX_ICONS = 10;

    public function __construct(private readonly RemoteImage $images) {}

    public function name(): string
    {
        return 'arrange_status_page';
    }

    public function title(): string
    {
        return 'Organise a status page into sections';
    }

    public function description(): string
    {
        return 'Organises a machine’s status page in one go, for “tidy up my status page”. Call get_status_page first to see the services (and their suggested names) and the sections there now. '
            .'`sections`: [{name, services, icon_url?}]: each section is one of the machine’s service groups, so this also groups those services in the dashboard’s service list (a service sits in one group at most; listing it here moves it out of any other). A section with the name of an existing group (any case) updates that group; others are created. Each section holds exactly the services you list (keys, names, labels or app:<name>), and they’re shown on the page. '
            .'On the page, monitors always lead in their own section (“Websites”, or “Endpoints” when some aren’t websites), so they can’t go in a section; then sections follow in alphabetical order of name (not the order you give: name them to sort the way you want), then services in no section (headed “Services” when there are monitors). Within a section, services keep the order the machine reports them in. Long pages (more than two sections and eight items) fold each section to one line, opened when something in it is wrong. '
            .'`labels`: [{service, label}] names on the page for services or monitors (≤60; empty for the suggested name). `hide_unlisted`: hide every service not in one of these sections. `monitors`: exactly which monitors show (the rest are hidden); left out, monitors keep their settings. `include_new`: whether services found later show by default. '
            .'`icon_url`: a public https:// PNG/JPEG/WebP/GIF for the group in the dashboard (the public page doesn’t show icons); null removes the group’s icon. '
            .'`remove_other_groups`: delete the machine’s groups that aren’t in sections (their services go back to the main list); it needs `confirm` set to the machine’s name: ask the person first, since they may have made those groups themselves. '
            .'Doesn’t publish the page or change its address (update_status_page does). Creates the page, switched off, if there’s none. Returns the sections and a preview of the page.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'sections' => Schema::array(Schema::object([
                'name' => Schema::string('The section’s name (≤60), shown as its heading.', maxLength: 60),
                'services' => Schema::array(Schema::service(), 'The services in it: keys, names, labels or app:<name>.', 500),
                'icon_url' => Schema::string('Optional: a public https:// image for the group in the dashboard. null removes its icon.', maxLength: 2048),
            ], ['name', 'services']), 'The sections. They show in alphabetical order of name.', 50),
            'labels' => Schema::array(Schema::object([
                'service' => Schema::string('A service (key, name, label or app:<name>) or a monitor (monitor:<id>).', maxLength: 300),
                'label' => Schema::string('Its name on the page (≤60). Empty: the suggested name.', maxLength: 60),
            ], ['service', 'label']), 'Names on the page.', 500),
            'hide_unlisted' => Schema::boolean('Hide every service that isn’t in one of these sections (default false: they keep their settings).'),
            'monitors' => Schema::array(Schema::string('A monitor: monitor:<id> or monitor:<name>.', maxLength: 300), 'Exactly the monitors to show; the machine’s others are hidden. Leave out to keep them as they are.', 200),
            'include_new' => Schema::boolean('Show services and monitors found from now on, by default.'),
            'remove_other_groups' => Schema::boolean('Delete the machine’s service groups that aren’t among these sections. Needs confirm.'),
            'confirm' => Schema::confirm('the machine’s name (only with remove_other_groups)'),
        ], ['machine', 'sections']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        // Only with remove_other_groups, which needs confirm.
        return true;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', StatusPages::reference()],
            'sections' => ['present', 'array', 'list', 'max:50'],
            'sections.*' => ['array:name,services,icon_url'],
            'sections.*.name' => ['required', 'string', 'max:60'],
            'sections.*.services' => ['present', 'array', 'list', 'max:500'],
            'sections.*.services.*' => ['required', 'string', 'max:300'],
            'sections.*.icon_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:https'],
            'labels' => ['sometimes', 'array', 'list', 'max:500'],
            'labels.*' => ['array:service,label'],
            'labels.*.service' => ['required', 'string', 'max:300'],
            'labels.*.label' => ['present', 'nullable', 'string', 'max:60'],
            'hide_unlisted' => ['sometimes', 'boolean'],
            'monitors' => ['sometimes', 'array', 'list', 'max:200'],
            'monitors.*' => ['required', 'string', 'max:300'],
            'include_new' => ['sometimes', 'boolean'],
            'remove_other_groups' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $machine = $context->machine($args['machine']);
        $page = StatusPages::machinePage($context, $machine);

        // Sections, each service resolved, none in two.
        $sections = [];
        $placed = [];
        $aliases = [];
        foreach ($args['sections'] as $index => $section) {
            $name = trim($section['name']);
            if ($name === '') {
                throw new ToolError('Invalid arguments: section '.($index + 1).' needs a name.');
            }
            $lower = mb_strtolower($name);
            if (isset($sections[$lower])) {
                throw new ToolError("Two sections are called “{$name}”. Give each section its own name, or put their services together.");
            }

            $keys = [];
            foreach ($section['services'] as $reference) {
                if (str_starts_with(mb_strtolower(trim($reference)), 'monitor:')) {
                    throw new ToolError("“{$reference}” is a monitor. Monitors can’t go in a section: they always lead the page in their own (“Websites”, or “Endpoints” when some aren’t websites). Use `monitors` to choose which show.");
                }
                $target = StatusPages::resolve($context, $machine, $reference);
                if (isset($placed[$target['key']])) {
                    throw new ToolError("“{$reference}” ({$target['key']}) is in two sections, “{$placed[$target['key']]}” and “{$name}”. A service sits in one section at most.");
                }
                $placed[$target['key']] = $name;
                $keys[] = $target['key'];
                array_push($aliases, ...$target['aliases']);
            }

            $sections[$lower] = [
                'name' => $name,
                'keys' => $keys,
                // A URL to fetch, false to take the icon off, null to leave it.
                'icon' => array_key_exists('icon_url', $section) ? ($section['icon_url'] ?? false) : null,
            ];
        }

        $labels = [];
        foreach ($args['labels'] ?? [] as $entry) {
            $target = StatusPages::resolve($context, $machine, $entry['service']);
            if (array_key_exists($target['key'], $labels)) {
                throw new ToolError("“{$entry['service']}” has two labels. Give each service one.");
            }
            $label = trim((string) $entry['label']);
            $labels[$target['key']] = $label === '' ? null : $label;
        }

        $monitors = null;
        if (array_key_exists('monitors', $args)) {
            $monitors = [];
            foreach ($args['monitors'] as $reference) {
                $reference = trim($reference);
                $target = StatusPages::resolve($context, $machine, str_starts_with(mb_strtolower($reference), 'monitor:') ? $reference : 'monitor:'.$reference);
                $monitors[$target['key']] = true;
            }
        }

        // Which existing groups the sections take over, and which go.
        $existing = $machine->serviceGroups->sortBy('id')->values();
        $matched = [];
        foreach (array_keys($sections) as $lower) {
            $group = $existing->first(fn (ServiceGroup $g) => mb_strtolower(trim($g->name)) === $lower);
            if ($group !== null) {
                $matched[$lower] = $group;
            }
        }
        $matchedIds = array_map(fn (ServiceGroup $g) => $g->id, $matched);
        $removing = ($args['remove_other_groups'] ?? false)
            ? $existing->reject(fn (ServiceGroup $g) => in_array($g->id, $matchedIds, true))->values()
            : collect();
        if ($removing->isNotEmpty() && trim((string) ($args['confirm'] ?? '')) !== $machine->name) {
            throw new ToolError('Removing the other groups ('.$removing->pluck('name')->map(fn ($n) => "“{$n}”")->implode(', ')."), with their icons, needs confirm set to the machine’s name, “{$machine->name}”. Ask the person first: they may have made those groups themselves.");
        }

        // How each service and monitor shows.
        $config = StatusPages::config($page, array_key_exists('include_new', $args) ? (bool) $args['include_new'] : null);
        $now = fn (array $config, string $key) => $config[$key] ?? ['shown' => (bool) $page->include_new, 'label' => null];
        foreach (array_keys($placed) as $key) {
            $config[$key] = ['shown' => true, 'label' => $now($config, $key)['label']];
        }
        if ($args['hide_unlisted'] ?? false) {
            foreach (StatusPages::inventory($machine) as $service) {
                if (! isset($placed[$service['key']])) {
                    $config[$service['key']] = ['shown' => false, 'label' => $now($config, $service['key'])['label']];
                }
            }
        }
        if ($monitors !== null) {
            foreach (Monitor::query()->where('machine_id', $machine->id)->pluck('id') as $id) {
                $key = 'monitor:'.$id;
                $config[$key] = ['shown' => isset($monitors[$key]), 'label' => $now($config, $key)['label']];
            }
        }
        foreach ($labels as $key => $label) {
            $config[$key] = ['shown' => $now($config, $key)['shown'], 'label' => $label];
        }

        $changes = array_key_exists('include_new', $args) ? ['include_new' => (bool) $args['include_new']] : [];
        $data = StatusPages::validateMachinePage($context->workspace, $page, StatusPages::payload($page, $changes, $config));

        // Icons are downloaded before anything changes, so a bad one changes nothing.
        $icons = [];
        $wanted = array_filter($sections, fn ($s) => is_string($s['icon']));
        if (count($wanted) > self::MAX_ICONS) {
            throw new ToolError('At most '.self::MAX_ICONS.' icons in one call. Set the rest in another call.');
        }
        try {
            foreach ($wanted as $lower => $section) {
                $file = $this->images->fetch($section['icon']);
                $icons[$lower] = $file;
                $validator = Validator::make(['icon' => $file], ['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);
                if ($validator->fails()) {
                    throw new ToolError("The icon for “{$section['name']}” can’t be used: ".implode(' ', $validator->errors()->all()));
                }
            }

            $before = $this->snapshot($machine->id);
            [$saved, $groups] = DB::transaction(function () use ($context, $machine, $page, $data, $sections, $matched, $removing, $aliases) {
                // The dashboard files deployed apps by app:<name>: take those out too.
                if ($aliases !== []) {
                    foreach ($machine->serviceGroups()->get() as $group) {
                        $remaining = array_values(array_diff($group->services, $aliases));
                        if ($remaining !== $group->services) {
                            $group->forceFill(['services' => $remaining])->save();
                        }
                    }
                }
                foreach ($removing as $group) {
                    $group->delete();
                }
                $groups = [];
                foreach ($sections as $lower => $section) {
                    $groups[$lower] = ServiceGroup::put($machine, $matched[$lower] ?? new ServiceGroup, $section['name'], $section['keys']);
                }

                return [StatusPages::saveMachinePage($context, $machine, $page, $data), $groups];
            });

            // Files after the database, as the group controller handles them.
            foreach ($removing as $group) {
                if ($group->icon_path !== null) {
                    Storage::disk('public')->delete($group->icon_path);
                }
            }
            foreach ($sections as $lower => $section) {
                if ($section['icon'] === null) {
                    continue;
                }
                $group = $groups[$lower];
                $previous = $group->icon_path;
                $file = $icons[$lower] ?? null;
                $group->forceFill(['icon_path' => $file instanceof UploadedFile ? $file->store("service-icons/{$machine->id}", 'public') : null])->save();
                if ($previous !== null) {
                    Storage::disk('public')->delete($previous);
                }
            }
        } finally {
            foreach ($icons as $file) {
                @unlink($file->getPathname());
            }
        }

        $groupsChanged = $before !== $this->snapshot($machine->id);
        if ($groupsChanged && ! $saved['created'] && $saved['changed'] === []) {
            // The sections changed though the page's own settings didn't.
            $context->record('status_page.update', $page->title ?: $machine->name, $machine);
        }

        $machine->load(['statusPage', 'serviceGroups']);
        $page->setRelation('machine', $machine);
        $report = new StatusReport($page);
        $preview = $report->toArray();

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'created' => $saved['created'],
            'changed' => array_values(array_unique([...$saved['changed'], ...($groupsChanged ? ['sections'] : [])])),
            'removedGroups' => $removing->pluck('name')->values()->all(),
            'sections' => StatusPages::groups($machine, $report),
            'page' => StatusPages::settings($page),
            'preview' => StatusPages::preview($preview),
            'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status"),
            'notes' => $this->notes($context, $page, $report, $preview),
        ];
    }

    /**
     * What's worth telling the person about how it came out.
     *
     * @param  array<string, mixed>  $preview
     * @return list<string>
     */
    private function notes(McpContext $context, StatusPage $page, StatusReport $report, array $preview): array
    {
        $notes = [];
        $named = array_values(array_filter(array_column($preview['sections'], 'name'), fn ($n) => $n !== null));
        if ($named !== []) {
            $notes[] = 'On the page, top to bottom: '.implode(', ', array_map(fn ($n) => "“{$n}”", $named)).'.';
        }
        $loose = count(array_filter($report->services(), fn (array $s) => $s['group'] === null));
        if ($loose > 0) {
            $notes[] = "{$loose} shown service".($loose === 1 ? ' is' : 's are').' in no section and show'.($loose === 1 ? 's' : '').' at the end. hide_unlisted hides them.';
        }
        $empty = array_values(array_filter(
            StatusPages::groups($page->machine, $report),
            fn (array $g) => $g['onPage'] === [],
        ));
        if ($empty !== []) {
            $notes[] = 'Not on the page (nothing shown in '.(count($empty) === 1 ? 'it' : 'them').'): '.implode(', ', array_map(fn ($g) => "“{$g['name']}”", $empty)).'.';
        }
        if (! $context->workspace->hasStatusPages()) {
            $notes[] = 'Status pages come with Pro and Business: this page can’t be published on the current plan.';
        } elseif (! $page->enabled) {
            $notes[] = 'The page is switched off, so visitors don’t see this yet. update_status_page with enabled true publishes it (check with the person first).';
        }

        return $notes;
    }

    /**
     * The machine's groups as they stand, to tell whether anything changed.
     *
     * @return array<int, array{0: string, 1: list<string>, 2: string|null}>
     */
    private function snapshot(int $machineId): array
    {
        return ServiceGroup::query()->where('machine_id', $machineId)->orderBy('id')->get()
            ->mapWithKeys(fn (ServiceGroup $g) => [$g->id => [$g->name, $g->services, $g->icon_path]])
            ->all();
    }
}
