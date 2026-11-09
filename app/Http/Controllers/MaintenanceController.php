<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Maintenance\ScheduledMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Planned work: put a machine in maintenance, now or planned ahead (with
 * a restart or updates for ServerOS to run), or mute a monitor, for a
 * while. Alerts and event workflows hold off until it ends on its own.
 */
class MaintenanceController extends Controller
{
    /** Minutes people can choose. */
    public const DURATIONS = [30, 60, 120, 240, 480, 1440];

    #[Audited]
    public function start(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'minutes' => ['required', 'integer', Rule::in(self::DURATIONS)],
            'reason' => ['nullable', 'string', 'max:200'],
            'public' => ['required', 'boolean'],
        ]);

        $machine->forceFill([
            'maintenance_until' => now()->addMinutes($data['minutes']),
            'maintenance_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
            'maintenance_public' => $data['public'],
        ])->save();
        Activity::record($workspace, 'machine.maintenance', $machine->name, $machine, meta: [
            'minutes' => (int) $data['minutes'],
            'reason' => $machine->maintenance_reason,
        ]);

        return back();
    }

    /**
     * Plan maintenance for later: shown on the status page from now, and
     * started on time by maintenance:tick.
     */
    #[Audited]
    public function schedule(Request $request, Workspace $workspace, Machine $machine, ScheduledMaintenance $maintenance): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(MaintenanceWindow::MAX_DAYS_AHEAD)->toIso8601String()],
            'minutes' => ['required', 'integer', Rule::in(self::DURATIONS)],
            'reason' => ['nullable', 'string', 'max:200'],
            'public' => ['required', 'boolean'],
            'action' => ['required', Rule::in(MaintenanceWindow::ACTIONS)],
        ], [
            'starts_at.after' => 'Pick a time in the future.',
            'starts_at.before' => 'Maintenance can be planned up to '.MaintenanceWindow::MAX_DAYS_AHEAD.' days ahead.',
        ]);

        $window = $maintenance->schedule($machine, $request->user(), Date::parse($data['starts_at'])->utc(), (int) $data['minutes'], $data['reason'] ?? null, (bool) $data['public'], $data['action']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Maintenance planned for '.$window->starts_at->format('j M, H:i').' UTC.'.($window->public ? ' It’s on the status page now.' : '')]);

        return back();
    }

    /** Call off planned maintenance, or end one that's under way. */
    #[Audited]
    public function cancel(Request $request, Workspace $workspace, Machine $machine, MaintenanceWindow $window, ScheduledMaintenance $maintenance): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $window->machine_id === $machine->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $maintenance->cancel($window, $request->user());

        return back();
    }

    /** The quietest hour of the week on this machine, to suggest in the dialog. */
    public function suggest(Workspace $workspace, Machine $machine, ScheduledMaintenance $maintenance): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(['quiet' => $maintenance->quietTime($machine)]);
    }

    #[Audited]
    public function end(Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if ($machine->inMaintenance()) {
            $machine->forceFill(['maintenance_until' => null, 'maintenance_reason' => null])->save();
            Activity::record($workspace, 'machine.maintenance_end', $machine->name, $machine);
        } else {
            AuditContext::nothingChanged();
        }
        // A planned window under way ends with it.
        MaintenanceWindow::query()->where('machine_id', $machine->id)->where('state', 'active')
            ->update(['state' => 'done', 'phase' => null, 'finished_at' => now(), 'outcome' => 'Ended early from the panel.']);

        return back();
    }

    #[Audited]
    public function mute(Request $request, Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        abort_unless($monitor->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', Rule::in(self::DURATIONS)]])['minutes'];
        $monitor->forceFill(['muted_until' => now()->addMinutes($minutes)])->save();
        Activity::record($workspace, 'monitor.mute', $monitor->name, $monitor->machine, meta: ['minutes' => $minutes]);

        return back();
    }

    #[Audited]
    public function unmute(Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        abort_unless($monitor->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $monitor->forceFill(['muted_until' => null])->save();
        Activity::record($workspace, 'monitor.unmute', $monitor->name, $monitor->machine);

        return back();
    }
}
