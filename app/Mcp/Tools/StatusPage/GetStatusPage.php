<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\StatusPages\StatusReport;

/**
 * Everything about a machine's status page: its settings, every service
 * and monitor it could list with how it treats each, its sections, and a
 * preview, as the Status page tab of the machine's settings shows them.
 */
final class GetStatusPage extends Tool
{
    public function name(): string
    {
        return 'get_status_page';
    }

    public function title(): string
    {
        return 'Get a machine’s status page';
    }

    public function description(): string
    {
        return 'Returns a machine’s status page in full, like the Status page tab of its settings: its settings (enabled, live, public, slug, title, description, notice, includeNew, showUptime, the page, badge, JSON and feed URLs, and subscribers: how many people confirmed email updates); '
            .'every service from the latest scan with whether the page shows it (`shown`), the name it’s given there (`label`, null for the `suggested` name), its section, the name the panel uses (`panelName`), its app, and `retired` for an old app version kept for rollback (usually best hidden); '
            .'every monitor on the machine (key monitor:<id>), which lead the page; the sections, which are the machine’s service groups, in the order the page shows them (by name, ungrouped services last); and `preview`, what the page shows right now (headline, sections with their components, statuses, uptime). '
            .'If the machine has no page yet, `exists` is false and this is the draft its settings start from. Use update_status_page to change settings and arrange_status_page to organise it into sections.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
        ], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', StatusPages::reference()]]);
        $machine = $context->machine($data['machine']);
        $page = StatusPages::machinePage($context, $machine);
        $report = new StatusReport($page);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name, 'online' => $machine->isOnline()],
            'planAllows' => $context->workspace->hasStatusPages(),
            'page' => StatusPages::settings($page),
            'services' => StatusPages::serviceRows($page),
            'monitors' => StatusPages::monitorRows($page, $report),
            'sections' => StatusPages::groups($machine, $report),
            'preview' => StatusPages::preview($report->toArray()),
            'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status"),
            'note' => match (true) {
                ! $context->workspace->hasStatusPages() => 'Status pages come with Pro and Business; this workspace’s plan doesn’t include them, so the page can’t be published.',
                ! $page->exists => 'This machine has no status page yet: these are the settings it would start with. update_status_page or arrange_status_page creates it.',
                ! $page->enabled => 'The page is switched off: visitors get “not found”.',
                default => null,
            },
        ];
    }
}
