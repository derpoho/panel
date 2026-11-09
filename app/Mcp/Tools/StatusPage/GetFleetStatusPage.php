<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\Monitor;
use App\Support\StatusPages\FleetStatusReport;

/**
 * The workspace's fleet status page (Business): its settings, the machines
 * it can cover, and a preview, as Settings → Status page shows them.
 */
final class GetFleetStatusPage extends Tool
{
    public function name(): string
    {
        return 'get_fleet_status_page';
    }

    public function title(): string
    {
        return 'Get the fleet status page';
    }

    public function description(): string
    {
        return 'Returns the workspace’s fleet status page, one page at /status/{slug} with a section for each machine it covers (a Business feature), like Settings → Status page: its settings (enabled, live, public, slug, title, description, notice, showUptime, and whether it covers every machine, including ones connected later, or a chosen list), the page, badge and JSON URLs, '
            .'the connected machines with whether each is on the page and whether it has its own status page settings (those pick which of its services show here, and under what names; a machine without them shows everything it runs), and `preview`, what the page shows right now. '
            .'On the fleet page each machine is one section; a machine’s own sections show as a suffix on the name, like “Shop (Web)”. If there’s no fleet page yet, `exists` is false and this is the draft. Change it with update_fleet_status_page; organise a machine’s part with arrange_status_page.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $page = StatusPages::fleetPage($context);

        $machines = $workspace->machines()->whereNotNull('uid')->with('statusPage')->orderBy('name')->get()
            ->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'online' => $machine->isOnline(),
                'onPage' => $page->machines === null || in_array($machine->id, $page->machines, true),
                // Its own page picks which of its services show.
                'hasOwnSettings' => $machine->statusPage !== null,
            ])->values()->all();

        // Uptime monitors on no machine (a site hosted elsewhere), to pick from.
        $monitors = Monitor::query()->where('workspace_id', $workspace->id)->whereNull('machine_id')->orderBy('name')->get()
            ->map(fn (Monitor $monitor) => [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'paused' => $monitor->paused,
                'onPage' => in_array($monitor->id, $page->monitors ?? [], true),
            ])->values()->all();

        return [
            'planAllows' => $workspace->hasFleetStatusPage(),
            'page' => StatusPages::fleetSettings($page),
            'machines' => $machines,
            'monitors' => $monitors,
            // What layout.sections can pull in, by key.
            'components' => FleetStatusReport::components($page),
            'preview' => StatusPages::preview((new FleetStatusReport($page))->toArray()),
            'settingsUrl' => $context->url('/settings/status-page'),
            'note' => match (true) {
                ! $workspace->hasFleetStatusPage() => 'One status page for every machine comes with Business. On Pro, each machine can have its own (list_status_pages).',
                ! $page->exists => 'There’s no fleet page yet: these are the settings it would start with. update_fleet_status_page creates it.',
                ! $page->enabled => 'The fleet page is switched off: visitors get “not found”.',
                default => null,
            },
        ];
    }
}
