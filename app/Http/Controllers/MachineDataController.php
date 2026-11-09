<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Logs\LogSkipRules;
use App\Support\Machines\MachineDataEraser;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PDOException;

/**
 * Clearing what machines have sent ServerOS (logs, usage history, what
 * discovery found) while they stay connected: from a machine's settings,
 * or for machines picked on the Machines page.
 */
class MachineDataController extends Controller
{
    /**
     * Clear one machine's data. Clearing everything needs the machine's
     * name typed, like removing it does.
     */
    #[Audited]
    public function clear(Request $request, Workspace $workspace, Machine $machine, MachineDataEraser $eraser): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(MachineDataEraser::KINDS)],
            'confirm' => ['nullable', 'string', 'max:255'],
        ]);
        $kinds = MachineDataEraser::only($data['kinds']);
        if (count($kinds) === count(MachineDataEraser::KINDS) && trim((string) ($data['confirm'] ?? '')) !== $machine->name) {
            throw ValidationException::withMessages(['confirm' => "Type {$machine->name} to clear everything it has sent."]);
        }

        $result = $this->run($workspace, $machine, $kinds, $request, $eraser);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['queued']
            ? "Clearing {$machine->name}’s ".self::words($kinds).'. It stays connected, and its settings show when it’s done.'
            : "Cleared {$machine->name}’s ".self::words($kinds).'. It’s still connected.']);

        return back();
    }

    /**
     * Delete the logs skipped services already sent, offered after new
     * service skip rules are saved. Only services the machine's skip rules
     * now match are taken; the machine stays connected and nothing is sent
     * to it.
     */
    #[Audited]
    public function clearSkipped(Request $request, Workspace $workspace, Machine $machine, MachineDataEraser $eraser): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'services' => ['required', 'array', 'min:1', 'max:200'],
            'services.*' => ['required', 'string', 'max:200'],
        ]);
        $rules = ($machine->log_skip ?? LogSkipRules::none())['services'];
        $services = array_values(array_unique(array_filter(
            array_map('strval', $data['services']),
            fn (string $service) => collect($rules)->contains(fn (string $rule) => LogSkipRules::matchesService($rule, $service)),
        )));
        if ($services === []) {
            throw ValidationException::withMessages(['services' => 'None of those services are skipped on '.$machine->name.' any more.']);
        }

        try {
            $result = $eraser->startServiceLogs($machine, $services, $request->user());
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['services' => "Couldn’t reach where {$machine->name}’s logs are kept. Nothing was deleted from there; try again once it’s back."]);
        }
        Activity::record($workspace, 'machine.data_clear', $machine->name, $machine, meta: [
            ...MachineDataEraser::activityMeta($result['counts'], $result['queued']),
            'skipped_services' => count($services),
        ]);

        $lines = number_format($result['counts'][MachineDataEraser::LOGS] ?? 0);
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['queued']
            ? "Deleting {$lines} lines from skipped services on {$machine->name}. Its settings show when it’s done."
            : "Deleted {$lines} lines from skipped services on {$machine->name}."]);

        return back();
    }

    /**
     * Clear the same kinds on several machines: one activity entry each.
     */
    #[Audited]
    public function bulk(Request $request, Workspace $workspace, MachineDataEraser $eraser): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'machines' => ['required', 'array', 'min:1', 'max:200'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(MachineDataEraser::KINDS)],
        ]);
        $kinds = MachineDataEraser::only($data['kinds']);
        $machines = $workspace->machines()->whereKey(array_map('intval', $data['machines']))->orderBy('name')->get();

        $queued = false;
        foreach ($machines as $machine) {
            $queued = $this->run($workspace, $machine, $kinds, $request, $eraser)['queued'] || $queued;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => ($queued ? 'Clearing ' : 'Cleared ').self::words($kinds).' on '
            .($machines->count() === 1 ? $machines->first()->name : $machines->count().' machines').'. They stay connected.']);

        return back();
    }

    /**
     * @param  list<string>  $kinds
     * @return array{queued: bool, counts: array<string, int>}
     */
    private function run(Workspace $workspace, Machine $machine, array $kinds, Request $request, MachineDataEraser $eraser): array
    {
        try {
            $result = $eraser->start($machine, $kinds, $request->user());
        } catch (QueryException|PDOException) {
            // A workspace's own log store that isn't answering.
            throw ValidationException::withMessages(['kinds' => "Couldn’t reach where {$machine->name}’s logs are kept. Nothing was cleared from there; try again once it’s back."]);
        }
        Activity::record($workspace, 'machine.data_clear', $machine->name, $machine, meta: MachineDataEraser::activityMeta($result['counts'], $result['queued']));

        return $result;
    }

    /** @param  list<string>  $kinds */
    private static function words(array $kinds): string
    {
        if (count($kinds) === count(MachineDataEraser::KINDS)) {
            return 'collected data';
        }
        $words = array_map(fn (string $kind) => match ($kind) {
            MachineDataEraser::LOGS => 'logs',
            MachineDataEraser::METRICS => 'usage history',
            default => 'discovered services',
        }, $kinds);

        return count($words) === 1 ? $words[0] : implode(', ', array_slice($words, 0, -1)).' and '.end($words);
    }
}
