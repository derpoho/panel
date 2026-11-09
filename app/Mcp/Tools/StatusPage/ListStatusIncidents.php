<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\StatusIncident;

/**
 * The incidents written for the workspace's status pages, open ones first.
 */
final class ListStatusIncidents extends Tool
{
    public function name(): string
    {
        return 'list_status_incidents';
    }

    public function title(): string
    {
        return 'List status page incidents';
    }

    public function description(): string
    {
        return 'Lists the incidents written for the workspace’s status pages (not the outages monitors find on their own), open ones first, then ones resolved in the last 14 days: id, title, impact (degraded, partial or major), state (investigating, identified, monitoring or resolved), the machine it’s about (null: the workspace page only), when it started and was resolved, and its updates, newest first. One about a machine shows on that machine’s page and on the workspace’s page. post_status_incident posts one; update_status_incident adds an update or resolves it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'open' => Schema::boolean('true: only open incidents.'),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['open' => ['sometimes', 'boolean']]);

        $incidents = $context->workspace->statusIncidents()->shown()
            ->when($data['open'] ?? false, fn ($q) => $q->whereNull('resolved_at'))
            ->with(['updates', 'machine'])
            ->orderByRaw('resolved_at is null desc')
            ->latest('started_at')
            ->limit(50)
            ->get();

        return [
            'incidents' => array_values($incidents->map(fn (StatusIncident $i) => [...$i->toReport(withMachine: true), 'machineId' => $i->machine_id])->all()),
            'settingsUrl' => $context->url('/settings/status-page'),
        ];
    }
}
