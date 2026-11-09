<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\StatusPage;
use App\Support\StatusPages\StatusReport;

/**
 * Change a machine's status page: any of its settings, and which services
 * and monitors it shows under what names, as the Status page tab of the
 * machine's settings does. What isn't given stays as it is.
 */
final class UpdateStatusPage extends Tool
{
    private const FIELDS = ['enabled', 'public', 'slug', 'title', 'description', 'notice', 'notice_kind', 'include_new', 'show_uptime'];

    public function name(): string
    {
        return 'update_status_page';
    }

    public function title(): string
    {
        return 'Change a machine’s status page';
    }

    public function description(): string
    {
        return 'Changes a machine’s status page, like the Status page tab of its settings. Every argument but machine is optional and only what you give changes. '
            .'`enabled` publishes the page (true) or takes it down (false); turning it on needs Pro or Business. `public` false makes it members-only. `slug` is the address, /status/{slug} on the main ServerOS domain (3–60 lowercase letters, numbers and single dashes, unique across every status page). '
            .'`title` (≤80), `description` (≤280) and `notice` (≤500, a banner at the top; `notice_kind` info, maintenance or incident) take an empty string to clear them. `include_new`: whether services and monitors found later show by default (the ones there now keep showing as they do). `show_uptime`: the 90-day uptime bars. '
            .'`services`: per-service or per-monitor changes, [{service, shown?, label?}], where service is a service key/name/label/app:<name> or monitor:<id>; label is the name on the page (≤60; empty to go back to the suggested name); section, for a monitor only, puts it in a section of its own named that (≤60, like “Cloud (UK)”; empty to put it back with the other monitors). Services and monitors you don’t list keep their settings. '
            .'If the machine has no page yet, one is created (switched off unless you set enabled). Publishing makes the page visible to anyone with the link when it’s public: check with the person before publishing or changing the address of a live page. '
            .'To group services into sections, use arrange_status_page. Returns what changed, the page’s settings and a preview of what visitors will see. Noted in the activity log.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'enabled' => Schema::boolean('Publish the page (true) or take it down (false). Needs Pro or Business to turn on.'),
            'public' => Schema::boolean('true: anyone with the link. false: only members of this workspace, signed in.'),
            'slug' => Schema::string('The address: /status/{slug}. 3–60 lowercase letters, numbers and single dashes.', maxLength: 60),
            'title' => Schema::string('The page’s title (≤80). Empty: the machine’s name.', maxLength: 80),
            'description' => Schema::string('A line under the title (≤280). Empty to clear.', maxLength: 280),
            'notice' => Schema::string('A banner at the top of the page (≤500), for an incident or planned work. Empty to clear.', maxLength: 500),
            'notice_kind' => Schema::string('How the notice looks.', StatusPage::NOTICE_KINDS),
            'include_new' => Schema::boolean('Show services and monitors found from now on, by default.'),
            'show_uptime' => Schema::boolean('Show the 90-day uptime bars.'),
            'services' => Schema::array(Schema::object([
                'service' => Schema::string('A service (key, name, label or app:<name>) or a monitor (monitor:<id>).', maxLength: 300),
                'shown' => Schema::boolean('Show it on the page.'),
                'label' => Schema::string('Its name on the page (≤60). Empty: the suggested name.', maxLength: 60),
                'only_during_problems' => Schema::boolean('true: on the page only while it isn’t working (degraded or down), off it while it’s fine. Implies shown.'),
                'section' => Schema::string('Monitors only: a section of its own on the page, named this (≤60). Empty: with the other monitors.', maxLength: 60),
            ], ['service']), 'Per-service and per-monitor changes. Ones not listed keep their settings.', 500),
        ], ['machine']);
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
            'machine' => ['required', StatusPages::reference()],
            'enabled' => ['sometimes', 'boolean'],
            'public' => ['sometimes', 'boolean'],
            'slug' => ['sometimes', 'string'],
            'title' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'notice' => ['sometimes', 'nullable', 'string'],
            'notice_kind' => ['sometimes', 'nullable', 'string'],
            'include_new' => ['sometimes', 'boolean'],
            'show_uptime' => ['sometimes', 'boolean'],
            'services' => ['sometimes', 'array', 'list', 'max:500'],
            'services.*' => ['array:service,shown,label,only_during_problems,section'],
            'services.*.section' => ['sometimes', 'nullable', 'string', 'max:60'],
            'services.*.service' => ['required', 'string', 'max:300'],
            'services.*.shown' => ['sometimes', 'boolean'],
            'services.*.label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'services.*.only_during_problems' => ['sometimes', 'boolean'],
        ]);

        $changes = array_intersect_key($args, array_flip(self::FIELDS));
        if ($changes === [] && empty($args['services'])) {
            throw new ToolError('Nothing to change: give at least one setting or a services list. get_status_page shows the page as it is.');
        }
        foreach (['enabled', 'public', 'include_new', 'show_uptime'] as $flag) {
            if (array_key_exists($flag, $changes)) {
                $changes[$flag] = (bool) $changes[$flag];
            }
        }
        if (isset($changes['slug'])) {
            $changes['slug'] = trim($changes['slug']);
        }

        $machine = $context->machine($args['machine']);
        $page = StatusPages::machinePage($context, $machine);
        $config = StatusPages::config($page, $changes['include_new'] ?? null);

        // Each change lands on the entry as it shows now.
        $seen = [];
        foreach ($args['services'] ?? [] as $change) {
            $target = StatusPages::resolve($context, $machine, $change['service']);
            $key = $target['key'];
            if (isset($seen[$key])) {
                throw new ToolError("“{$change['service']}” is listed twice in services (it’s {$key}). List each service once.");
            }
            $seen[$key] = true;

            $entry = $config[$key] ?? ['shown' => (bool) $page->include_new, 'label' => null];
            // Naming a service for the page means it should be on it.
            if (! isset($config[$key]) && ! array_key_exists('shown', $change) && trim((string) ($change['label'] ?? '')) !== '') {
                $entry['shown'] = true;
            }
            if (array_key_exists('shown', $change)) {
                $entry['shown'] = (bool) $change['shown'];
            }
            if (array_key_exists('label', $change)) {
                $label = trim((string) $change['label']);
                $entry['label'] = $label === '' ? null : $label;
            }
            if (array_key_exists('only_during_problems', $change)) {
                if ($change['only_during_problems']) {
                    $entry['shown'] = true;
                    $entry['quiet'] = true;
                } else {
                    unset($entry['quiet']);
                }
            }
            if (array_key_exists('section', $change)) {
                if (! str_starts_with($key, 'monitor:')) {
                    throw new ToolError("Only monitors take a section here; “{$change['service']}” is a service. Use arrange_status_page to put services in sections.");
                }
                $section = trim((string) $change['section']);
                unset($entry['section']);
                if ($section !== '') {
                    $entry['section'] = $section;
                }
            }
            if (! $entry['shown']) {
                unset($entry['quiet']);
            }
            $config[$key] = $entry;
        }

        $data = StatusPages::validateMachinePage($context->workspace, $page, StatusPages::payload($page, $changes, $config));
        $saved = StatusPages::saveMachinePage($context, $machine, $page, $data);

        $machine->load(['statusPage', 'serviceGroups']);
        $page->setRelation('machine', $machine);
        $report = new StatusReport($page);

        $notes = [];
        if ($saved['changed'] === [] && ! $saved['created']) {
            $notes[] = 'Nothing changed: the page already had those settings.';
        }
        if (array_key_exists('notice_kind', $changes) && ! $page->notice) {
            $notes[] = 'notice_kind only applies with a notice; there’s none.';
        }
        if ($page->enabled && ! $page->live()) {
            $notes[] = 'The page is switched on but isn’t served: status pages need Pro or Business.';
        }
        if (! $page->enabled) {
            $notes[] = 'The page is switched off: visitors get “not found”. Set enabled to true to publish it.';
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'created' => $saved['created'],
            'changed' => $saved['changed'],
            'page' => StatusPages::settings($page),
            'preview' => StatusPages::preview($report->toArray()),
            'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status"),
            'notes' => $notes,
        ];
    }
}
