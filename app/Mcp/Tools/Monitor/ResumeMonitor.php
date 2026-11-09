<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;
use App\Support\Monitors\MonitorSettings;

/**
 * Start checking a paused monitor again, as its page's Resume does.
 */
final class ResumeMonitor extends Tool
{
    public function name(): string
    {
        return 'resume_monitor';
    }

    public function title(): string
    {
        return 'Resume a monitor';
    }

    public function description(): string
    {
        return 'Resumes a paused uptime monitor: it’s checked at the next round (within a minute) and alerts again as usual. Call check_monitor_now to check it straight away. Returns the monitor.';
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

        if (! $monitor->paused) {
            AuditContext::nothingChanged();

            return ['monitor' => Monitors::row($monitor, $context), 'note' => "{$monitor->name} isn’t paused."];
        }

        MonitorSettings::pause($monitor, false);
        $context->record('monitor.resume', $monitor->name, $monitor->machine);

        return ['monitor' => Monitors::row($monitor, $context), 'note' => "Resumed {$monitor->name}: it’s checked at the next round."];
    }
}
