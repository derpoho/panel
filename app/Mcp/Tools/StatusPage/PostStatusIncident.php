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
 * Posts an incident on the status pages, as “Post incident” in Settings ›
 * Status page does.
 */
final class PostStatusIncident extends Tool
{
    public function name(): string
    {
        return 'post_status_incident';
    }

    public function title(): string
    {
        return 'Post a status page incident';
    }

    public function description(): string
    {
        return 'Posts an incident on the status pages, in the workspace’s own words, as “Post incident” in Settings › Status page does: `title` (≤120, what’s wrong, like “Checkout is failing”), `impact` (degraded, partial or major: an open incident makes the page’s headline at least that bad), `state` (investigating, identified or monitoring) and a first `message` (≤5000). With `machine` (id or name) it shows on that machine’s page and the workspace’s; without, on the workspace’s page only. Visitors see it straight away, so check the wording with the person first. Follow up with update_status_incident. Noted in the activity log. Returns the incident.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'title' => Schema::string('What’s wrong, like “Checkout is failing” (≤120).', maxLength: 120),
            'impact' => Schema::string('How bad it is.', StatusIncident::IMPACTS),
            'state' => Schema::string('Where it stands.', ['investigating', 'identified', 'monitoring']),
            'message' => Schema::string('The first update visitors read (≤5000).', maxLength: 5000),
            'machine' => Schema::machine('The machine it’s about: its id or name. Leave out for the workspace’s page only.'),
        ], ['title', 'impact', 'state', 'message']);
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
        $machine = isset($arguments['machine']) && $arguments['machine'] !== '' ? $context->machine($arguments['machine']) : null;
        $validator = Validator::make([...$arguments, 'machine_id' => $machine?->id], StatusIncidents::openRules($context->workspace));
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }
        $data = $validator->validated();

        $incident = StatusIncidents::open($context->workspace, $machine, $context->user, $data['title'], $data['impact'], $data['state'], $data['message']);
        $context->record(StatusIncidents::action($incident->state, opened: true), $incident->title, $machine);

        return ['incident' => [...$incident->load(['updates', 'machine'])->toReport(withMachine: true), 'machineId' => $incident->machine_id]];
    }
}
