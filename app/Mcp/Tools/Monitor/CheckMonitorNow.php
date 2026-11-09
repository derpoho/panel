<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Monitor;
use App\Support\Audit\NotAudited;
use App\Support\Monitors\MonitorRunner;

/**
 * Check a monitor now, as its page's Check now button does, and say what
 * it found.
 */
final class CheckMonitorNow extends Tool
{
    public function name(): string
    {
        return 'check_monitor_now';
    }

    public function title(): string
    {
        return 'Check a monitor now';
    }

    public function description(): string
    {
        return 'Checks an uptime monitor straight away instead of waiting for its next round, and returns what it found (ok, response time, status code, error) and the monitor as it is after. The check counts like any other: two failed checks in a row take it down (an incident opens and alerts go out) and a passing one brings it back. Takes up to the monitor’s timeout. A paused monitor isn’t checked: resume_monitor first.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['monitor' => Monitors::schema()], ['monitor']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    #[NotAudited('Runs the check now; what it finds is recorded as usual')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['monitor' => ['required', Monitors::reference()]]);
        $monitor = Monitors::find($context, $data['monitor']);
        if ($monitor->paused) {
            throw new ToolError("{$monitor->name} is paused, so it isn’t checked. Resume it first (resume_monitor).");
        }
        Monitors::throttle($context, 'check', 20, 'That’s a lot of checks in a minute.');

        $last = (int) $monitor->checks()->max('id');
        app(MonitorRunner::class)->run(collect([$monitor->load('workspace')]));
        $monitor = $monitor->fresh('machine');
        $check = $monitor->checks()->where('id', '>', $last)->latest('id')->first();

        return [
            'check' => $check ? [
                'at' => $check->checked_at->toIso8601String(),
                'ok' => $check->ok,
                'ms' => $check->response_ms,
                'statusCode' => $check->status_code,
                'error' => $check->error,
            ] : null,
            'monitor' => Monitors::row($monitor, $context),
            'note' => $this->note($monitor, $check?->ok),
        ];
    }

    private function note(Monitor $monitor, ?bool $ok): string
    {
        $left = max(1, Monitor::CONFIRM_AFTER - $monitor->failures);

        return match (true) {
            $ok === null => 'No result came back.',
            $ok => "{$monitor->name} is up.",
            $monitor->status === 'down' => "{$monitor->name} is down: {$monitor->last_error}",
            default => "The check failed ({$monitor->last_error}). {$left} more failed ".($left === 1 ? 'check' : 'checks').' in a row and it counts as down; it’s looked at again within '.min(30, $monitor->interval_seconds).' s.',
        };
    }
}
