<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a service group; its services go back to the main list, as on
 * the machine's services list.
 */
final class DeleteServiceGroup extends Tool
{
    public function name(): string
    {
        return 'delete_service_group';
    }

    public function title(): string
    {
        return 'Delete a service group';
    }

    public function description(): string
    {
        return 'Deletes a service group on a machine, and its icon. Its services stay, back in the main list; nothing on the machine changes. The group is also a section of the machine’s status page, which then lists those services without it. Ask the person before deleting, then pass confirm set to the group’s name exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'group' => Schema::string('The group: its id or name (as list_service_groups shows).', maxLength: 120),
            'confirm' => Schema::confirm('the group’s name'),
        ], ['machine', 'group', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServiceWork::reference()],
            'group' => ['required', ServiceWork::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $machine = $context->machine($data['machine']);
        $group = ServiceGroups::find($machine, $data['group']);
        ServiceWork::confirm($data, [$group->name], 'group');

        if ($group->icon_path !== null) {
            Storage::disk('public')->delete($group->icon_path);
        }
        $group->delete();
        $context->record('service_group.delete', $group->name, $machine);

        return [
            'deleted' => ['id' => $group->id, 'name' => $group->name, 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'servicesUngrouped' => count($group->services ?? []),
        ];
    }
}
