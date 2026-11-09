<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\NotAudited;
use Illuminate\Support\Facades\Storage;

/**
 * Take a service group's icon away.
 */
final class RemoveServiceGroupIcon extends Tool
{
    public function name(): string
    {
        return 'remove_service_group_icon';
    }

    public function title(): string
    {
        return 'Remove a service group’s icon';
    }

    public function description(): string
    {
        return 'Removes the icon of a service group on a machine; the group keeps its name and services. Does nothing if it has no icon.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'group' => Schema::string('The group: its id or name (as list_service_groups shows).', maxLength: 120),
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

    #[NotAudited('Cosmetic')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServiceWork::reference()],
            'group' => ['required', ServiceWork::reference()],
        ]);
        $machine = $context->machine($data['machine']);
        $group = ServiceGroups::find($machine, $data['group']);
        $had = $group->icon_path !== null;

        if ($had) {
            Storage::disk('public')->delete($group->icon_path);
            $group->forceFill(['icon_path' => null])->save();
        }

        return [
            'group' => ['id' => $group->id, 'name' => $group->name, 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'removed' => $had,
        ];
    }
}
