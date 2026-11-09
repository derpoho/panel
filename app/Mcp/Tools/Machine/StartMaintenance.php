<?php

namespace App\Mcp\Tools\Machine;

use App\Http\Controllers\MaintenanceController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Support\Maintenance\ScheduledMaintenance;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Put a machine in maintenance for a while, as the maintenance dialog
 * does: alerts and event workflows about it hold off until it ends.
 */
final class StartMaintenance extends Tool
{
    public function name(): string
    {
        return 'start_maintenance';
    }

    public function title(): string
    {
        return 'Start maintenance on a machine';
    }

    public function description(): string
    {
        return 'Puts a machine in maintenance for planned work: for the chosen minutes (30, 60, 120, 240, 480 or 1440), alerts about it and its monitors are held and event workflows about it don’t start, so reboots and restarts don’t page anyone; anything still wrong when the time is up is alerted then. Real outages in that time aren’t alerted either, so keep it to planned work the person knows about. reason says what’s happening (shown in the panel); public (default true) shows a maintenance notice on its status page instead of an outage. Starting again replaces the current window. Use end_maintenance to finish early. '
            .'To plan it for later, give starts_at (ISO 8601, up to 60 days ahead): it shows as upcoming on the status page straight away and starts on time, and action can be "reboot" (ServerOS restarts the machine, then ends maintenance early once it’s back) or "updates" (installs every waiting update, restarts if they need it, ends early once back). Returns the window.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'minutes' => Schema::integer('How long: 30, 60 (default), 120, 240, 480 or 1440 minutes.') + ['enum' => MaintenanceController::DURATIONS],
            'reason' => Schema::string('What’s happening, like “Upgrading Postgres to 17”.', maxLength: 200),
            'public' => Schema::boolean('Show a maintenance notice on its status page (default true).'),
            'starts_at' => Schema::string('Plan it for later: when it starts, ISO 8601 (like 2026-10-10T04:00:00Z). Leave out to start now.', maxLength: 40),
            'action' => Schema::string('Planned only: what ServerOS does when it starts. none (default), reboot, or updates (then a restart if needed).', MaintenanceWindow::ACTIONS),
        ], ['machine']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate([...['minutes' => 60, 'public' => true], ...ServerWork::trimmed($arguments, 'reason')], [
            'machine' => ['required', ServerWork::reference()],
            'minutes' => ['required', 'integer', Rule::in(MaintenanceController::DURATIONS)],
            'reason' => ['nullable', 'string', 'max:200'],
            'public' => ['required', 'boolean'],
        ], ['minutes.in' => 'minutes must be one of '.implode(', ', MaintenanceController::DURATIONS).'.']);
        $machine = $context->machine($data['machine']);
        ServerWork::throttle($context, 'maintenance', 20);

        if (filled($arguments['starts_at'] ?? null)) {
            return $this->plan($context, $machine, $arguments, $data);
        }

        $machine->forceFill([
            'maintenance_until' => now()->addMinutes($data['minutes']),
            'maintenance_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
            'maintenance_public' => (bool) $data['public'],
        ])->save();
        $context->record('machine.maintenance', $machine->name, $machine, [
            'minutes' => (int) $data['minutes'],
            'reason' => $machine->maintenance_reason,
        ]);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'maintenance' => [
                'until' => $machine->maintenance_until->toIso8601String(),
                'reason' => $machine->maintenance_reason,
                'public' => $machine->maintenance_public,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function plan(McpContext $context, Machine $machine, array $arguments, array $data): array
    {
        $plan = $this->validate($arguments, [
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(MaintenanceWindow::MAX_DAYS_AHEAD)->toIso8601String()],
            'action' => ['nullable', Rule::in(MaintenanceWindow::ACTIONS)],
        ], ['starts_at.after' => 'starts_at must be in the future.', 'starts_at.before' => 'starts_at can be up to '.MaintenanceWindow::MAX_DAYS_AHEAD.' days ahead.']);

        try {
            $window = $context->within(fn () => app(ScheduledMaintenance::class)->schedule($machine, $context->user, Date::parse($plan['starts_at'])->utc(), (int) $data['minutes'], $data['reason'] ?? null, (bool) $data['public'], $plan['action'] ?? 'none'));
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', Arr::flatten($e->errors())));
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'planned' => $window->toCard(),
            'note' => $window->public ? 'It shows as upcoming on the status page now.' : null,
        ];
    }
}
