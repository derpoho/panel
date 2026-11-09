<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use App\Support\Monitors\MonitorSettings;

/**
 * Change a monitor's settings, as its Edit dialog does. What isn't given
 * stays as it is.
 */
final class UpdateMonitor extends Tool
{
    /** The arguments that change something. */
    private const FIELDS = ['name', 'type', 'url', 'host', 'port', 'interval', 'timeout', 'expected_status', 'keyword', 'machine', 'alerts'];

    public function name(): string
    {
        return 'update_monitor';
    }

    public function title(): string
    {
        return 'Change a monitor';
    }

    public function description(): string
    {
        return 'Changes an uptime monitor’s settings, as its Edit dialog does: give only what changes, everything else stays. The same rules as create_monitor apply to the result (a public address, an interval the plan allows, a timeout of 2–30 s); a plan that now allows less than its current interval needs a longer one before anything else can change. '
            .'Switching between http and tcp needs the new target (url, or host and port). expected_status 0 and keyword "" go back to “any 2xx or 3xx” and no keyword; machine "" unlinks it. A new target starts its record fresh (failure count and certificate details) and is checked at the next round. Pausing, muting and checking have their own tools. Returns the monitor and what changed.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['monitor' => Monitors::schema(), ...MonitorInput::properties()], ['monitor']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $this->validate($arguments, ['monitor' => ['required', Monitors::reference()]]);
        $monitor = Monitors::find($context, $arguments['monitor']);

        if (array_intersect(self::FIELDS, array_keys($arguments)) === []) {
            throw new ToolError('Nothing to change: give at least one of '.implode(', ', self::FIELDS).'.');
        }

        $data = app(MonitorSettings::class)->validate(MonitorInput::from($context, $arguments, $monitor), $context->workspace);
        $before = Monitors::detail($monitor, $context)['settings'] + ['name' => $monitor->name];

        $changedTarget = MonitorSettings::update($monitor, $data);
        $monitor->load('machine');
        $after = Monitors::detail($monitor, $context)['settings'] + ['name' => $monitor->name];
        $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            AuditContext::nothingChanged();

            return [
                'monitor' => Monitors::detail($monitor, $context),
                'changed' => [],
                'note' => 'Nothing was different, so nothing changed.',
            ];
        }

        $context->record('monitor.update', $monitor->name, $monitor->machine);

        return [
            'monitor' => Monitors::detail($monitor, $context),
            'changed' => $changed,
            'note' => $changedTarget
                ? "Saved. {$monitor->name} now checks {$monitor->target()}: it’s checked at the next round (check_monitor_now checks it straight away)."
                : "Saved {$monitor->name}.",
        ];
    }
}
