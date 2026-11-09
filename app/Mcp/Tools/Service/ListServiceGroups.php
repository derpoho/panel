<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\ServiceGroup;

/**
 * A machine's service groups, as its services list shows them: the groups
 * people made, then the Compose projects nobody has grouped, which the
 * list stacks together on its own.
 */
final class ListServiceGroups extends Tool
{
    public function name(): string
    {
        return 'list_service_groups';
    }

    public function title(): string
    {
        return 'List a machine’s service groups';
    }

    public function description(): string
    {
        return 'Lists the service groups on a machine: named sets of its services, shown as one row that opens up on the machine’s page, and used as the sections of the machine’s status page. A service is in one group at most. Each group has its id, name, icon and services (key, name, status); missing lists keys of services it holds that the latest scan didn’t find. '
            .'stacks are Compose projects with several containers that no group has claimed, which the page shows together on their own; create_service_group can keep one as a group. ungrouped counts the services in neither.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['machine' => Schema::machine()], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', ServiceWork::reference()]]);
        $machine = $context->machine($data['machine']);
        $groups = ServiceGroups::of($machine);
        $services = $context->services($machine)->map(fn (array $s) => $context->describeService($machine, $s));

        $loose = $services->filter(fn (array $s) => ServiceGroups::containing($groups, $s) === null);
        $stacks = $loose->filter(fn (array $s) => filled($s['details']['compose_project'] ?? null))
            ->groupBy(fn (array $s) => (string) $s['details']['compose_project'])
            ->filter(fn ($inside) => $inside->count() > 1);
        $stacked = $stacks->flatten(1)->pluck('key')->all();

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'groups' => $groups->map(fn (ServiceGroup $group) => ServiceGroups::present($context, $machine, $group))->all(),
            'stacks' => $stacks->map(fn ($inside, $project) => [
                'name' => preg_replace('/^serveros-/', '', (string) $project),
                'composeProject' => (string) $project,
                'services' => $inside->map(fn (array $s) => ['key' => $s['key'], 'name' => $s['display'], 'status' => $s['status'] ?? 'unknown'])->values()->all(),
            ])->values()->all(),
            'ungrouped' => $loose->reject(fn (array $s) => in_array($s['key'], $stacked, true))->count(),
            'url' => $context->url("/machines/{$machine->id}"),
        ];
    }
}
