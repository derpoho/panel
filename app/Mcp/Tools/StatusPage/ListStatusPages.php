<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Support\StatusPages\StatusReport;

/**
 * Every status page in the workspace: each machine's, and the fleet page
 * that covers several (Business).
 */
final class ListStatusPages extends Tool
{
    public function name(): string
    {
        return 'list_status_pages';
    }

    public function title(): string
    {
        return 'List status pages';
    }

    public function description(): string
    {
        return 'Lists the workspace’s status pages: for each machine (by name), whether it has a page (`exists`), whether it’s switched on (`enabled`) and actually served (`live`: on, on a plan that has status pages), public or members-only, its address (slug, URL, badge and JSON URLs), title, how many services and monitors it shows and its headline right now; '
            .'and the workspace’s fleet page, one page with a section per machine (Business). Machines without a page are listed with exists false. '
            .'Use get_status_page for one machine’s page in full and get_fleet_status_page for the fleet page.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'only_existing' => Schema::boolean('Leave out machines that have no status page.'),
            'limit' => Schema::integer('At most this many machines (default 100).', 1, 500),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'only_existing' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $workspace = $context->workspace;

        $query = $workspace->machines()
            ->when($data['only_existing'] ?? false, fn ($q) => $q->whereHas('statusPage'));
        $total = (clone $query)->count();
        $machines = $query->with(['statusPage', 'serviceGroups'])->orderBy('name')->limit($data['limit'] ?? 100)->get();

        $rows = $machines->map(function (Machine $machine) use ($context) {
            $machine->setRelation('workspace', $context->workspace);
            $page = $machine->statusPage;
            $row = [
                'machine' => ['id' => $machine->id, 'name' => $machine->name, 'status' => Present::connection($machine)],
                'exists' => $page !== null,
                'enabled' => (bool) $page?->enabled,
                'live' => (bool) $page?->live(),
            ];
            if ($page === null) {
                return [...$row, 'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status")];
            }

            $page->setRelation('machine', $machine);
            $report = new StatusReport($page);
            $services = $report->services();
            $monitors = $report->monitors();

            return [
                ...$row,
                'public' => (bool) $page->public,
                'slug' => $page->slug,
                'title' => $page->title ?: $machine->name,
                ...StatusPages::addresses($page->slug),
                'shows' => ['services' => count($services), 'monitors' => count($monitors), 'sections' => count(array_unique(array_filter(array_column($services, 'group'))))],
                'overall' => $report->overall(array_column($services, 'status'), array_column($monitors, 'status')),
                'notice' => $page->notice ? ['text' => $page->notice, 'kind' => $page->notice_kind ?? 'info'] : null,
                'updatedAt' => $page->updated_at?->toIso8601String(),
                'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status"),
            ];
        })->values()->all();

        $fleet = $workspace->fleetStatusPage()->first();
        $fleet?->setRelation('workspace', $workspace);

        return [
            'planAllows' => [
                'statusPages' => $workspace->hasStatusPages(),
                'fleetStatusPage' => $workspace->hasFleetStatusPage(),
            ],
            'machines' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'fleet' => $fleet === null
                ? [
                    'exists' => false,
                    'available' => $workspace->hasFleetStatusPage(),
                    'note' => $workspace->hasFleetStatusPage()
                        ? 'No fleet page yet: update_fleet_status_page makes one.'
                        : 'One status page for every machine comes with Business.',
                    'settingsUrl' => $context->url('/settings/status-page'),
                ]
                : [
                    'exists' => true,
                    'available' => $workspace->hasFleetStatusPage(),
                    'enabled' => (bool) $fleet->enabled,
                    'live' => $fleet->live(),
                    'public' => (bool) $fleet->public,
                    'slug' => $fleet->slug,
                    'title' => $fleet->title ?: $workspace->name,
                    ...StatusPages::addresses($fleet->slug),
                    'everyMachine' => $fleet->machines === null,
                    'machines' => $fleet->machines,
                    'settingsUrl' => $context->url('/settings/status-page'),
                ],
        ];
    }
}
