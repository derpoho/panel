<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Support\Audit\AuditContext;

/**
 * Rename a service group or change what's in it, as "Edit group" on the
 * machine's services list.
 */
final class UpdateServiceGroup extends Tool
{
    public function name(): string
    {
        return 'update_service_group';
    }

    public function title(): string
    {
        return 'Change a service group';
    }

    public function description(): string
    {
        return 'Renames a service group on a machine and/or changes its services. services replaces the whole set; add and remove change it a few at a time (services is applied first). Services added that are in another group move to this one (movedFrom says which), since a service is in one group at most. Name services by key, name, label or a deployed app’s name; remove also takes a key listed under missing in list_service_groups. Nothing on the machine changes. Returns the group as it is now.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'group' => Schema::string('The group: its id or name (as list_service_groups shows).', maxLength: 120),
            'name' => Schema::string('A new name (up to 60 characters).', maxLength: 60),
            'services' => Schema::array(Schema::service(), 'The services it should hold, replacing what it has (an empty list empties it).', 500),
            'add' => Schema::array(Schema::service(), 'Services to put in it, keeping the rest.', 500),
            'remove' => Schema::array(Schema::service(), 'Services to take out of it; they go back to the main list.', 500),
        ], ['machine', 'group']);
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
        $data = $this->validate($arguments, [
            ...ServiceGroups::rules(false),
            'group' => ['required', ServiceWork::reference()],
            'add' => ['sometimes', 'array', 'list', 'max:500'],
            'add.*' => ['string', 'max:300'],
            'remove' => ['sometimes', 'array', 'list', 'max:500'],
            'remove.*' => ['string', 'max:300'],
        ]);
        if (! isset($data['name']) && ! isset($data['services']) && ! isset($data['add']) && ! isset($data['remove'])) {
            throw new ToolError('Nothing to change: pass name, services, add or remove.');
        }
        $machine = $context->machine($data['machine']);
        $group = ServiceGroups::find($machine, $data['group']);

        $members = isset($data['services']) ? ServiceGroups::members($context, $machine, $data['services']) : $group->services ?? [];
        if (isset($data['add'])) {
            $members = [...$members, ...ServiceGroups::members($context, $machine, $data['add'])];
        }
        foreach ($data['remove'] ?? [] as $reference) {
            $members = array_values(array_diff($members, $this->keysOf($context, $machine, $members, $reference)));
        }
        $members = array_values(array_unique($members));

        $moved = ServiceGroups::losing($machine, $group, $members);
        $before = ['name' => $group->name, 'services' => $group->services ?? []];
        ServiceGroup::put($machine, $group, isset($data['name']) ? trim($data['name']) : $group->name, $members);

        $sorted = fn (array $list): array => collect($list)->sort()->values()->all();
        $renamed = $before['name'] !== $group->name;
        $regrouped = $sorted($before['services']) !== $sorted($members);
        if ($renamed || $regrouped) {
            $context->record('service_group.update', $group->name, $machine, array_filter([
                'from' => $renamed ? $before['name'] : null,
                'services' => $regrouped ? count($members) : null,
            ], fn ($v) => $v !== null));
        } else {
            AuditContext::nothingChanged();
        }

        return [
            'group' => ServiceGroups::present($context, $machine, $group->fresh()),
            'before' => $before,
            'movedFrom' => $moved,
        ];
    }

    /**
     * The keys in the group a reference to remove means: the key itself
     * (even for a service that's gone), or the service it names.
     *
     * @param  list<string>  $members
     * @return list<string>
     */
    private function keysOf(McpContext $context, Machine $machine, array $members, string $reference): array
    {
        if (in_array(trim($reference), $members, true)) {
            return [trim($reference)];
        }
        $service = $context->service($machine, $reference);

        return [$service['key'], ServiceWork::memberKey($service)];
    }
}
