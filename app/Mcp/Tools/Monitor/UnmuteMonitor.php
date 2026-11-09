<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;

/**
 * Let a muted monitor alert again before its time is up, as the "Alerts
 * muted" banner's End now does.
 */
final class UnmuteMonitor extends Tool
{
    public function name(): string
    {
        return 'unmute_monitor';
    }

    public function title(): string
    {
        return 'Unmute a monitor’s alerts';
    }

    public function description(): string
    {
        return 'Ends a mute_monitor mute early, so the monitor alerts again from its next check. It doesn’t end its machine’s maintenance, which holds alerts too (end_maintenance does that; the result says if it still applies). Returns the monitor.';
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
        $wasMuted = $monitor->muted_until !== null && $monitor->muted_until->isFuture();

        if ($wasMuted) {
            $monitor->forceFill(['muted_until' => null])->save();
            $context->record('monitor.unmute', $monitor->name, $monitor->machine);
        } else {
            AuditContext::nothingChanged();
        }

        $machine = $monitor->machine_id !== null ? $monitor->machine : null;
        $note = $wasMuted ? "Alerts for {$monitor->name} are on again." : "{$monitor->name} wasn’t muted.";
        if ($machine?->inMaintenance()) {
            $note .= " Its machine {$machine->name} is in maintenance until {$machine->maintenance_until->toIso8601String()}, so alerts stay held until then (end_maintenance ends it).";
        }

        return ['monitor' => Monitors::row($monitor, $context), 'note' => $note];
    }
}
