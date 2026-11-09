<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Models\ServiceGroup;

/**
 * Group some of a machine's services under a name, as "New group" on the
 * machine's services list.
 */
final class CreateServiceGroup extends Tool
{
    public function name(): string
    {
        return 'create_service_group';
    }

    public function title(): string
    {
        return 'Create a service group';
    }

    public function description(): string
    {
        return 'Creates a service group on a machine: a name (up to 60 characters) for a set of its services, shown as one row on the machine’s page and as a section of its status page. A service is in one group at most, so services already in another group move to this one (movedFrom says which). Name services by key, name, label or a deployed app’s name; an app stays in the group across redeploys. Nothing on the machine changes. Returns the group.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'name' => Schema::string('The group’s name, like “Website” or “Data”.', maxLength: 60),
            'services' => Schema::array(Schema::service(), 'The services to put in it (can be empty, to fill later).', 500),
        ], ['machine', 'name']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ServiceGroups::rules(true));
        $machine = $context->machine($data['machine']);
        $members = ServiceGroups::members($context, $machine, $data['services'] ?? []);
        $moved = ServiceGroups::losing($machine, null, $members);

        $group = ServiceGroup::put($machine, new ServiceGroup, trim($data['name']), $members);
        $context->record('service_group.create', $group->name, $machine, ['services' => count($members)]);

        return [
            'group' => ServiceGroups::present($context, $machine, $group),
            'movedFrom' => $moved,
        ];
    }
}
