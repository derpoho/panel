<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\NotAudited;
use Illuminate\Support\Facades\Storage;

/**
 * Take a machine's icon away, back to the default.
 */
final class RemoveMachineIcon extends Tool
{
    public function name(): string
    {
        return 'remove_machine_icon';
    }

    public function title(): string
    {
        return 'Remove a machine’s icon';
    }

    public function description(): string
    {
        return 'Removes the icon set for a machine, so the panel shows the default one again. Only the picture changes; set_machine_icon can give it a new one.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['machine' => Schema::machine()], ['machine']);
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
        $data = $this->validate($arguments, ['machine' => ['required', ServerWork::reference()]]);
        $machine = $context->machine($data['machine']);

        $had = $machine->icon_path !== null;
        if ($had) {
            Storage::disk('public')->delete($machine->icon_path);
            $machine->forceFill(['icon_path' => null])->save();
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'removed' => $had,
            'note' => $had ? null : 'It had no icon of its own.',
        ];
    }
}
