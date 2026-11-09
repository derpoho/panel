<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\MonitorSettings;

use function Illuminate\Support\defer;

/**
 * Add an uptime monitor, as the New monitor dialog does: checked the
 * same way, within the plan, and looked at straight away.
 */
final class CreateMonitor extends Tool
{
    public function name(): string
    {
        return 'create_monitor';
    }

    public function title(): string
    {
        return 'Add an uptime monitor';
    }

    public function description(): string
    {
        return 'Adds an uptime monitor that ServerOS checks from outside your machines. type http checks a URL (up: any 2xx or 3xx answer, or exactly expected_status; keyword, if given, must be on the page); type tcp checks that host:port accepts connections. Give url for http, or host and port for tcp (type is taken from that when left out). '
            .'interval defaults to every minute (or the plan’s shortest, if that’s longer), timeout to 10 s, alerts to on, and name to the URL’s hostname (or host:port). Two failed checks in a row count as down: an incident opens and alerts go out (see get_alert_settings); the first passing check closes it. The address must be reachable from the internet: private and internal addresses are refused, and the plan limits how many monitors there are and how often they’re checked. '
            .'The first check runs within a few seconds of adding it: call get_monitor a little later to see it. Returns the new monitor.';
    }

    public function inputSchema(): array
    {
        return Schema::object(MonitorInput::properties());
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        Monitors::throttle($context, 'create', 30, 'That’s a lot of monitors added in a minute.');
        $workspace = $context->workspace;
        if (($full = MonitorSettings::limitReached($workspace)) !== null) {
            throw new ToolError($full);
        }

        $data = app(MonitorSettings::class)->validate(MonitorInput::from($context, $arguments), $workspace);

        $monitor = $workspace->monitors()->create([
            ...$data,
            'status' => 'pending',
            'next_check_at' => now(),
        ]);
        $context->record('monitor.add', $monitor->name, $monitor->machine, ['target' => $monitor->target()]);

        // First result right away rather than at the next round.
        $runner = app(MonitorRunner::class);
        defer(fn () => $runner->run(collect([$monitor->fresh(['workspace'])])));

        return [
            'monitor' => Monitors::detail($monitor->fresh('machine'), $context),
            'note' => "Added {$monitor->name}. The first check runs within a few seconds: call get_monitor to see it.",
        ];
    }
}
