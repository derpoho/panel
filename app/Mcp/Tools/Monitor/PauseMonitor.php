<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;
use App\Support\Monitors\MonitorSettings;

/**
 * Stop checking a monitor for now, keeping its history, as its page's
 * Pause does.
 */
final class PauseMonitor extends Tool
{
    public function name(): string
    {
        return 'pause_monitor';
    }

    public function title(): string
    {
        return 'Pause a monitor';
    }

    public function description(): string
    {
        return 'Pauses an uptime monitor: it isn’t checked and doesn’t alert until resume_monitor, and its history is kept. Paused on purpose isn’t down: an open incident is closed, its status shows paused, and status pages leave it out. To keep checking but hold alerts for a while (planned work), use mute_monitor instead. Returns the monitor.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['monitor' => Monitors::schema()], ['monitor']);
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
        $data = $this->validate($arguments, ['monitor' => ['required', Monitors::reference()]]);
        $monitor = Monitors::find($context, $data['monitor']);

        if ($monitor->paused) {
            AuditContext::nothingChanged();

            return ['monitor' => Monitors::row($monitor, $context), 'note' => "{$monitor->name} was already paused."];
        }

        MonitorSettings::pause($monitor, true);
        $context->record('monitor.pause', $monitor->name, $monitor->machine);

        return ['monitor' => Monitors::row($monitor, $context), 'note' => "Paused {$monitor->name}. resume_monitor starts checking it again."];
    }
}
