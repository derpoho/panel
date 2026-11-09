<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Deploys\ServiceLabels;

/**
 * Give a service a name of its own in the panel, or go back to the one
 * ServerOS found, as the pencil on its page. Nothing on the machine
 * changes.
 */
final class RenameService extends Tool
{
    public function name(): string
    {
        return 'rename_service';
    }

    public function title(): string
    {
        return 'Rename a service';
    }

    public function description(): string
    {
        return 'Sets the name a service is shown by in ServerOS (like “Game panel” instead of calagopus-wings-wings-1), or with an empty label (or null) goes back to the name ServerOS found. Nothing on the machine changes, and the name stays when a container is recreated or an app redeployed. At most 60 characters. Returns the name it is shown by now.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'label' => Schema::string('The new name (up to 60 characters). An empty string (or null) goes back to the name ServerOS found.', maxLength: 60),
        ], ['machine', 'service', 'label']);
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
            ...ServiceWork::serviceArguments(),
            'label' => ['present', 'nullable', 'string', 'max:60'],
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);

        $before = ServiceLabels::display($machine, $service);
        ServiceLabels::set($machine, $service, $data['label'] ?? null);
        $machine = $machine->fresh();
        $after = ServiceLabels::display($machine, $service);

        if ($before !== $after) {
            $context->record('service.rename', $after, $machine, ['from' => $before]);
        }

        return [
            'service' => ['key' => $service['key'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'name' => $after,
            'label' => ServiceLabels::for($machine, $service),
            'previousName' => $before,
            'changed' => $before !== $after,
        ];
    }
}
