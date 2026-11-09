<?php

namespace App\Mcp\Tools\StatusPage;

use App\Http\Controllers\WorkspaceStatusPageController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Mcp\Tools\Monitor\Monitors;
use App\Models\MachineGroup;
use App\Models\StatusPage;
use App\Models\WorkspaceStatusPage;
use App\Support\Audit\AuditContext;
use App\Support\StatusPages\FleetStatusReport;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Change the workspace's fleet status page (Business), as Settings →
 * Status page does. What isn't given stays as it is.
 */
final class UpdateFleetStatusPage extends Tool
{
    private const FIELDS = ['enabled', 'public', 'slug', 'title', 'description', 'notice', 'notice_kind', 'show_uptime'];

    public function name(): string
    {
        return 'update_fleet_status_page';
    }

    public function title(): string
    {
        return 'Change the fleet status page';
    }

    public function description(): string
    {
        return 'Changes the workspace’s fleet status page (one page with a section per machine; Business), like Settings → Status page. Every argument is optional and only what you give changes; the page is created if there’s none. '
            .'`enabled` publishes it (true; needs Business) or takes it down. `public` false makes it members-only. `slug`: the address, /status/{slug} (3–60 lowercase letters, numbers and single dashes, unique across every status page, machine pages included). '
            .'`title` (≤80), `description` (≤280), `notice` (≤500, a banner; `notice_kind` info, maintenance or incident) take an empty string to clear. `show_uptime`: the 90-day uptime bars. '
            .'Which machines: `every_machine` true covers all of them, including ones connected later; `machines` sets exactly which (ids or names); `add_machines` / `remove_machines` change the list (from every machine, removing one makes a list of the rest). '
            .'`monitors` sets exactly which uptime monitors that aren’t on a machine (a site hosted elsewhere) show, first, with their own uptime (ids or names; an empty list shows none); monitors on a machine come with it. '
            .'Which services of a machine show, and their names and sections, come from that machine’s own status page settings (update_status_page, arrange_status_page). Check with the person before publishing or changing the address of a live page. Returns what changed, the settings and a preview. Noted in the activity log.';
    }

    public function inputSchema(): array
    {
        $machines = fn (string $what) => Schema::array(Schema::machine('A machine: its id or name.'), $what, 500);

        return Schema::object([
            'enabled' => Schema::boolean('Publish the page (true; needs Business) or take it down (false).'),
            'public' => Schema::boolean('true: anyone with the link. false: only members of this workspace, signed in.'),
            'slug' => Schema::string('The address: /status/{slug}. 3–60 lowercase letters, numbers and single dashes.', maxLength: 60),
            'title' => Schema::string('The page’s title (≤80). Empty: the workspace’s name.', maxLength: 80),
            'description' => Schema::string('A line under the title (≤280). Empty to clear.', maxLength: 280),
            'notice' => Schema::string('A banner at the top (≤500). Empty to clear.', maxLength: 500),
            'notice_kind' => Schema::string('How the notice looks.', StatusPage::NOTICE_KINDS),
            'show_uptime' => Schema::boolean('Show the 90-day uptime bars.'),
            'every_machine' => Schema::boolean('true: cover every machine, including ones connected later.'),
            'machines' => $machines('Exactly the machines to cover.'),
            'add_machines' => $machines('Machines to add to the page.'),
            'remove_machines' => $machines('Machines to take off the page.'),
            'monitors' => Schema::array(Schema::string('An uptime monitor on no machine: its id or name.', maxLength: 120), 'Exactly the uptime monitors on no machine to show (empty: none).', 200),
            'new_machines' => Schema::string('How a covered machine with no status page of its own shows: machine (one row for the server, the default) or services (its running services).', ['machine', 'services']),
            'machine_groups' => Schema::array(Schema::string('A machine group: its id or name.', maxLength: 120), 'Machine groups whose machines it also covers, including ones added to them later (empty: none). Only matters when not every_machine.', 100),
            'layout' => Schema::object([
                'sections' => Schema::array(Schema::object([
                    'name' => Schema::string('The section’s heading (≤60).', maxLength: 60),
                    'components' => Schema::array(Schema::string('A component key from get_fleet_status_page’s components, like 12:docker:abc.', maxLength: 320), 'In the order they show.', 500),
                ], ['components']), 'Sections of its own, first on the page, each taking components from any machine.', 50),
                'headings' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 60], 'description' => 'Headings for machines’ sections, keyed by machine id: {"12": "Game nodes · London"}. Empty to use the machine’s name.'],
                'order' => Schema::array(Schema::integer('A machine id.'), 'The order machines’ sections show in; the rest follow by name.', 500),
            ]) + ['description' => 'The page’s own arrangement. Replaces the whole layout; {} clears it.'],
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'enabled' => ['sometimes', 'boolean'],
            'public' => ['sometimes', 'boolean'],
            'slug' => ['sometimes', 'string'],
            'title' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'notice' => ['sometimes', 'nullable', 'string'],
            'notice_kind' => ['sometimes', 'nullable', 'string'],
            'show_uptime' => ['sometimes', 'boolean'],
            'every_machine' => ['sometimes', 'boolean'],
            'machines' => ['sometimes', 'array', 'list', 'max:500'],
            'machines.*' => [StatusPages::reference()],
            'add_machines' => ['sometimes', 'array', 'list', 'max:500'],
            'add_machines.*' => [StatusPages::reference()],
            'remove_machines' => ['sometimes', 'array', 'list', 'max:500'],
            'remove_machines.*' => [StatusPages::reference()],
            'monitors' => ['sometimes', 'array', 'list', 'max:200'],
            'monitors.*' => [StatusPages::reference()],
            'new_machines' => ['sometimes', 'in:machine,services'],
            'machine_groups' => ['sometimes', 'array', 'list', 'max:100'],
            'machine_groups.*' => [StatusPages::reference()],
            'layout' => ['sometimes', 'array'],
        ]);
        $workspace = $context->workspace;
        $page = StatusPages::fleetPage($context);

        $changes = array_intersect_key($args, array_flip(self::FIELDS));
        foreach (['enabled', 'public', 'show_uptime'] as $flag) {
            if (array_key_exists($flag, $changes)) {
                $changes[$flag] = (bool) $changes[$flag];
            }
        }
        if (isset($changes['slug'])) {
            $changes['slug'] = trim($changes['slug']);
        }

        $listed = array_key_exists('machines', $args) || array_key_exists('add_machines', $args) || array_key_exists('remove_machines', $args);
        $arranged = array_key_exists('new_machines', $args) || array_key_exists('machine_groups', $args) || array_key_exists('layout', $args);
        if ($changes === [] && ! array_key_exists('every_machine', $args) && ! $listed && ! array_key_exists('monitors', $args) && ! $arranged) {
            throw new ToolError('Nothing to change: give at least one setting, machines or monitors. get_fleet_status_page shows the page as it is.');
        }
        if (($args['every_machine'] ?? false) && $listed) {
            throw new ToolError('every_machine covers every machine, including ones connected later. To choose, give machines, add_machines or remove_machines without it.');
        }

        // Null: every machine, including ones connected later.
        $ids = $page->machines;
        if (($args['every_machine'] ?? null) === true) {
            $ids = null;
        } elseif (($args['every_machine'] ?? null) === false || $listed) {
            $ids = array_key_exists('machines', $args)
                ? array_map(fn ($ref) => $context->machine($ref)->id, $args['machines'])
                : ($ids ?? $workspace->machines()->whereNotNull('uid')->orderBy('name')->pluck('id')->all());
            foreach ($args['add_machines'] ?? [] as $ref) {
                $ids[] = $context->machine($ref)->id;
            }
            $removed = array_map(fn ($ref) => $context->machine($ref)->id, $args['remove_machines'] ?? []);
            $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), $removed));
        }

        $payload = [
            'enabled' => (bool) $page->enabled,
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'notice' => $page->notice,
            'notice_kind' => $page->notice_kind,
            'show_uptime' => (bool) $page->show_uptime,
            ...$changes,
            'machines' => $ids,
            'monitors' => array_key_exists('monitors', $args)
                ? array_map(fn ($ref) => Monitors::find($context, $ref)->id, $args['monitors'])
                : ($page->monitors ?? []),
        ];

        // The same rules as WorkspaceStatusPageController@update.
        $validator = Validator::make($payload, [
            'enabled' => ['required', 'boolean'],
            'public' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('workspace_status_pages', 'slug')->ignore($page->id), Rule::unique('status_pages', 'slug')],
            'title' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:280'],
            'notice' => ['nullable', 'string', 'max:500'],
            'notice_kind' => ['nullable', Rule::in(StatusPage::NOTICE_KINDS)],
            'show_uptime' => ['required', 'boolean'],
            'machines' => ['nullable', 'array', 'max:500'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'monitors' => ['nullable', 'array', 'max:200'],
            'monitors.*' => ['integer', Rule::exists('monitors', 'id')->where('workspace_id', $workspace->id)->whereNull('machine_id')],
        ], ['monitors.*.exists' => 'Only uptime monitors that aren’t on a machine go here; a machine’s monitors come with the machine.', ...StatusPages::MESSAGES]);
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();

        if ($data['enabled'] && ! $workspace->hasFleetStatusPage()) {
            throw new ToolError('One status page for every machine comes with Business.'.($page->enabled ? ' (This page is still switched on from before: set enabled to false to switch it off.)' : ''));
        }

        $before = $this->snapshot($page);
        $page->fill([
            'enabled' => $data['enabled'],
            'public' => $data['public'],
            'slug' => $data['slug'],
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'notice' => filled($data['notice'] ?? null) ? trim($data['notice']) : null,
            'notice_kind' => filled($data['notice'] ?? null) ? ($data['notice_kind'] ?? 'info') : null,
            'show_uptime' => $data['show_uptime'],
            'machines' => isset($data['machines']) ? array_values(array_unique(array_map('intval', $data['machines']))) : null,
            'monitors' => ! empty($data['monitors']) ? array_values(array_unique(array_map('intval', $data['monitors']))) : null,
        ]);
        if (array_key_exists('new_machines', $args)) {
            $page->new_machines = $args['new_machines'];
        }
        if (array_key_exists('machine_groups', $args)) {
            $groups = array_map(fn ($ref) => $this->group($context, (string) $ref), $args['machine_groups']);
            $page->machine_groups = $groups === [] ? null : array_values(array_unique($groups));
        }
        if (array_key_exists('layout', $args)) {
            $page->layout = WorkspaceStatusPageController::cleanLayout($args['layout']);
        }
        $page->workspace_id = $workspace->id;
        $after = $this->snapshot($page);
        $changed = array_keys(array_filter($after, fn ($value, $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
        $created = ! $page->exists;

        if ($created || $changed !== []) {
            $page->save();
            $context->record('status_page.fleet', $page->title ?: $workspace->name, meta: ['enabled' => $page->enabled]);
        } else {
            AuditContext::nothingChanged();
        }

        $notes = [];
        if (! $created && $changed === []) {
            $notes[] = 'Nothing changed: the page already had those settings.';
        }
        if (array_key_exists('notice_kind', $changes) && ! $page->notice) {
            $notes[] = 'notice_kind only applies with a notice; there’s none.';
        }
        if ($page->machines === [] && empty($page->monitors)) {
            $notes[] = 'The page covers no machines and no monitors, so it has nothing to show.';
        }
        if (! $page->enabled) {
            $notes[] = 'The page is switched off: visitors get “not found”. Set enabled to true to publish it.';
        } elseif (! $page->live()) {
            $notes[] = 'The page is switched on but isn’t served: the fleet page needs Business.';
        }

        return [
            'created' => $created,
            'changed' => $changed,
            'page' => StatusPages::fleetSettings($page),
            'preview' => StatusPages::preview((new FleetStatusReport($page))->toArray()),
            'settingsUrl' => $context->url('/settings/status-page'),
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(WorkspaceStatusPage $page): array
    {
        return [
            'enabled' => (bool) $page->enabled,
            'public' => (bool) $page->public,
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'notice' => $page->notice,
            'notice_kind' => $page->notice ? $page->notice_kind : null,
            'show_uptime' => (bool) $page->show_uptime,
            'machines' => $page->machines,
            'monitors' => $page->monitors,
            'machine_groups' => $page->machine_groups,
            'new_machines' => $page->new_machines ?? 'machine',
            'layout' => $page->layout,
        ];
    }

    /** A machine group of this workspace, by id or name. */
    private function group(McpContext $context, string $reference): int
    {
        $group = MachineGroup::query()->where('workspace_id', $context->workspace->id)
            ->where(fn ($q) => $q->where('name', $reference)->when(ctype_digit($reference), fn ($q) => $q->orWhere('id', (int) $reference)))
            ->first();
        if ($group === null) {
            throw new ToolError("No machine group “{$reference}” here. list_machine_groups shows them.");
        }

        return $group->id;
    }
}
