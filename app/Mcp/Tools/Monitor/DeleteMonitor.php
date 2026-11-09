<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Delete a monitor with its history, as its page's Delete does.
 */
final class DeleteMonitor extends Tool
{
    public function name(): string
    {
        return 'delete_monitor';
    }

    public function title(): string
    {
        return 'Delete a monitor';
    }

    public function description(): string
    {
        return 'Deletes an uptime monitor. Its checks, incidents and uptime history go with it, it drops off any status page that showed it, and workflows that wait on or check it will need another monitor. To stop checking but keep the history, use pause_monitor instead. Can’t be undone: ask the person first, then pass confirm set to the monitor’s name exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'monitor' => Monitors::schema(),
            'confirm' => Schema::confirm('the monitor’s name'),
        ], ['monitor', 'confirm']);
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
            'monitor' => ['required', Monitors::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $monitor = Monitors::find($context, $data['monitor']);
        Monitors::confirm($data, $monitor);

        $removed = ['id' => $monitor->id, 'name' => $monitor->name, 'target' => $monitor->target()];
        $context->record('monitor.remove', $monitor->name);
        $monitor->delete();

        return [
            'removed' => $removed,
            'note' => "Deleted {$removed['name']} and its history.",
        ];
    }
}
