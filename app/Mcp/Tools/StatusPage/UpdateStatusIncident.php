<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\StatusIncident;
use App\Support\StatusPages\StatusIncidents;
use Illuminate\Support\Facades\Validator;

/**
 * Posts an update on a status page incident, or resolves it.
 */
final class UpdateStatusIncident extends Tool
{
    public function name(): string
    {
        return 'update_status_incident';
    }

    public function title(): string
    {
        return 'Update a status page incident';
    }

    public function description(): string
    {
        return 'Posts an update on an incident written for the status pages (list_status_incidents shows them), as “Post update” does: `state` (investigating, identified, monitoring, or resolved to close it; any other state on a resolved one opens it again) and a `message` (≤5000) visitors read. `impact` (degraded, partial or major) changes how bad it’s marked. Visitors see it straight away, so check the wording with the person first. Noted in the activity log. Returns the incident with its updates.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'incident' => Schema::integer('The incident’s id, from list_status_incidents.', 1),
            'state' => Schema::string('Where it stands now.', StatusIncident::STATES),
            'message' => Schema::string('What visitors read (≤5000).', maxLength: 5000),
            'impact' => Schema::string('Change how bad it’s marked.', StatusIncident::IMPACTS),
        ], ['incident', 'state', 'message']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $incident = $context->workspace->statusIncidents()->find((int) ($arguments['incident'] ?? 0))
            ?? throw new ToolError('No incident with that id in this workspace. list_status_incidents shows them.');
        $validator = Validator::make($arguments, StatusIncidents::updateRules());
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();

        $incident = StatusIncidents::update($incident, $context->user, $data['state'], $data['message'], $data['impact'] ?? null);
        $context->record(StatusIncidents::action($data['state']), $incident->title, $incident->machine);

        return ['incident' => [...$incident->load(['updates', 'machine'])->toReport(withMachine: true), 'machineId' => $incident->machine_id]];
    }
}
