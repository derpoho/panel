<?php

namespace App\Mcp\Tools\Monitor;

use App\Http\Controllers\MaintenanceController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use Illuminate\Validation\Rule;

/**
 * Hold a monitor's alerts for a while, as its page's "Mute alerts for…"
 * does. It's still checked.
 */
final class MuteMonitor extends Tool
{
    public function name(): string
    {
        return 'mute_monitor';
    }

    public function title(): string
    {
        return 'Mute a monitor’s alerts';
    }

    public function description(): string
    {
        return 'Mutes an uptime monitor’s alerts for the chosen minutes (30, 60 (default), 120, 240, 480 or 1440), for planned work: it’s still checked and its incidents recorded, but no alert email or webhook goes out and workflows waiting on it don’t start until the time is up (going down during the mute isn’t alerted later; a certificate warning waits). It unmutes on its own. Muting again replaces the current time. unmute_monitor ends it early. To stop checking altogether use pause_monitor; for a whole machine, start_maintenance. Returns the monitor and when the mute ends.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'monitor' => Monitors::schema(),
            'minutes' => Schema::integer('How long: 30, 60 (default), 120, 240, 480 or 1440 minutes.') + ['enum' => MaintenanceController::DURATIONS],
        ], ['monitor']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate([...['minutes' => 60], ...$arguments], [
            'monitor' => ['required', Monitors::reference()],
            'minutes' => ['required', 'integer', Rule::in(MaintenanceController::DURATIONS)],
        ], ['minutes.in' => 'minutes must be one of '.implode(', ', MaintenanceController::DURATIONS).'.']);
        $monitor = Monitors::find($context, $data['monitor']);
        Monitors::throttle($context, 'mute', 20, 'That’s a lot of muting in a minute.');

        $minutes = (int) $data['minutes'];
        $monitor->forceFill(['muted_until' => now()->addMinutes($minutes)])->save();
        $context->record('monitor.mute', $monitor->name, $monitor->machine, ['minutes' => $minutes]);

        return [
            'monitor' => Monitors::row($monitor, $context),
            'mutedUntil' => $monitor->muted_until->toIso8601String(),
            'note' => "Alerts for {$monitor->name} are muted until {$monitor->muted_until->toIso8601String()}. It’s still checked.",
        ];
    }
}
