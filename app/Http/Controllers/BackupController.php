<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Backups\BackupRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Backups: where each machine's go, how often, and what has run.
 */
class BackupController extends Controller
{
    public const array SCHEDULES = ['off', 'daily', 'weekly'];

    public function index(Workspace $workspace, BackupRunner $runner): Response
    {
        $machines = $workspace->machines()->with('backupDestination')->orderBy('name')->get();

        return Inertia::render('workspace/Backups', [
            'workspace' => $workspace->toPageProps(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'ip' => $machine->ip_address,
                'connection' => MachineResource::connection($machine),
                'destinationId' => $machine->backup_destination_id,
                'schedule' => $machine->backup_schedule,
                'lastBackup' => $machine->last_backup_at?->toIso8601String(),
                'services' => count($machine->backupableServices()),
                'unavailable' => $runner->unavailable($machine),
            ]),
            'destinations' => $workspace->backupDestinations()->with('machine')->orderBy('name')->get()
                ->map(fn (BackupDestination $d) => [
                    ...$d->toPageProps(),
                    'machines' => $machines->where('backup_destination_id', $d->id)->count(),
                ]),
            'history' => function () use ($machines, $runner) {
                $backups = Backup::query()
                    ->whereIn('machine_id', $machines->modelKeys())
                    ->with(['machine', 'destination'])
                    ->latest('id')
                    ->limit(100)
                    ->get();
                $cannot = $runner->cannotRestoreAll($backups);

                return $backups->map(fn (Backup $backup) => [
                    ...$backup->toPageProps(),
                    'cannotRestore' => $cannot[$backup->id] ?? null,
                ]);
            },
        ]);
    }

    /**
     * Put a service's data back as it was in a backup. Its name typed back
     * confirms it; the daemon snapshots the data first, so it can be undone.
     */
    #[Audited]
    public function restore(Request $request, Workspace $workspace, Backup $backup, BackupRunner $runner): JsonResponse
    {
        abort_unless($backup->machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $confirm = (string) $request->validate(['confirm' => ['required', 'string', 'max:255']])['confirm'];
        if ($confirm !== $backup->service_name) {
            return response()->json(['errors' => ['confirm' => ['Type the service’s name to confirm.']]], 422);
        }

        try {
            $job = $runner->restore($backup, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'backup.restore', $backup->service_name, $backup->machine, meta: [
            'from' => $backup->created_at?->format('j M Y, H:i'),
            'undo' => $backup->trigger === 'pre-restore',
        ]);

        return response()->json(['jobId' => $job->id, 'state' => 'queued'], 202);
    }

    /**
     * Choose where a machine's backups go and how often they run.
     */
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'destination_id' => ['nullable', 'integer', Rule::exists('backup_destinations', 'id')->where('workspace_id', $workspace->id)],
            'schedule' => ['required', Rule::in(self::SCHEDULES)],
        ]);

        $destination = isset($data['destination_id']) ? BackupDestination::query()->whereKey($data['destination_id'])->first() : null;
        if ($destination?->machine_id === $machine->id) {
            throw ValidationException::withMessages(['destination_id' => 'A machine can\'t keep its own backups; pick somewhere else.']);
        }
        if ($destination === null && $data['schedule'] !== 'off') {
            throw ValidationException::withMessages(['schedule' => 'Choose where its backups go first.']);
        }

        $machine->forceFill([
            'backup_destination_id' => $destination?->id,
            'backup_schedule' => $data['schedule'],
        ]);
        if ($machine->isDirty(['backup_destination_id', 'backup_schedule'])) {
            Activity::record($workspace, 'backups.schedule', $machine->name, $machine, meta: ['schedule' => $data['schedule'], 'destination' => $destination?->name]);
        } else {
            AuditContext::nothingChanged();
        }
        $machine->save();

        return back();
    }

    /**
     * Back up a machine's managed services now.
     */
    #[Audited]
    public function run(Request $request, Workspace $workspace, Machine $machine, BackupRunner $runner): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        try {
            $started = $runner->run($machine, $request->user(), 'manual');
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'backup.run', $machine->name, $machine, meta: ['services' => $started]);

        return response()->json(['started' => $started], 202);
    }
}
