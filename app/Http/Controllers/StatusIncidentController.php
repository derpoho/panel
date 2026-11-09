<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\StatusIncident;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\StatusPages\StatusIncidents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Incidents written for status pages, from Settings › Status page: post
 * one, post updates until it's resolved, or delete one posted by mistake.
 */
class StatusIncidentController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(StatusIncidents::openRules($workspace));

        $machine = isset($data['machine_id']) ? Machine::query()->whereKey($data['machine_id'])->first() : null;
        $incident = StatusIncidents::open($workspace, $machine, $request->user(), $data['title'], $data['impact'], $data['state'], $data['message']);
        Activity::record($workspace, StatusIncidents::action($incident->state, opened: true), $incident->title, $machine);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, StatusIncident $incident): RedirectResponse
    {
        abort_unless($incident->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(StatusIncidents::updateRules());

        StatusIncidents::update($incident, $request->user(), $data['state'], $data['message'], $data['impact'] ?? null);
        Activity::record($workspace, StatusIncidents::action($data['state']), $incident->title, $incident->machine);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, StatusIncident $incident): RedirectResponse
    {
        abort_unless($incident->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $incident->delete();
        Activity::record($workspace, 'status_page.incident_delete', $incident->title, $incident->machine);

        return back();
    }
}
