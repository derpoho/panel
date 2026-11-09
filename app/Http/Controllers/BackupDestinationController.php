<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Backups\ReceiverKey;
use App\Support\Backups\S3Probe;
use App\Support\DaemonJobQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Where backups can go: an S3-compatible bucket, checked with a test
 * upload, or one of the workspace's machines, which the daemon sets up
 * to receive them over SFTP.
 */
class BackupDestinationController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace, S3Probe $probe, ReceiverKey $keys, DaemonJobQueue $queue): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $type = $request->validate(['type' => ['required', Rule::in(BackupDestination::TYPES)]])['type'];
        $destination = new BackupDestination(['type' => $type]);
        $destination->workspace_id = $workspace->id;

        if ($type === 's3') {
            $data = $this->s3($request, $probe);
            $destination->fill(['name' => $data['name'], 'settings' => $data['settings']])->save();
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:60'],
                'machine_id' => ['required', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            ]);
            $machine = Machine::query()->whereKey($data['machine_id'])->firstOrFail();
            if (($reason = $queue->unavailable($machine)) !== null) {
                throw ValidationException::withMessages(['machine_id' => $reason]);
            }

            $destination->fill(['name' => trim($data['name']), 'machine_id' => $machine->id])->save();
            $this->setUpReceiver($request, $destination, $keys, $queue);
        }

        Activity::record($workspace, 'backup_destination.add', $destination->name, meta: ['type' => $type]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $type === 's3'
            ? "Added {$destination->name}. A test upload went through."
            : "Setting up {$destination->name} to receive backups."]);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, BackupDestination $destination, S3Probe $probe): RedirectResponse
    {
        abort_unless($destination->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $before = $destination->name;
        if ($destination->type === 's3') {
            $data = $this->s3($request, $probe, $destination->settings);
            $destination->fill(['name' => $data['name'], 'settings' => $data['settings']])->save();
        } else {
            $destination->fill($request->validate(['name' => ['required', 'string', 'max:60']]))->save();
        }

        if ($destination->wasChanged()) {
            Activity::record($workspace, 'backup_destination.update', $destination->name, meta: array_filter([
                'from' => $destination->wasChanged('name') ? $before : null,
                'settings' => $destination->wasChanged('settings') ?: null,
            ], fn ($v) => $v !== null));
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * Run the receiving machine's setup again, after it failed.
     */
    #[Audited]
    public function retry(Request $request, Workspace $workspace, BackupDestination $destination, ReceiverKey $keys, DaemonJobQueue $queue): RedirectResponse
    {
        abort_unless($destination->workspace_id === $workspace->id && $destination->type === 'machine', 404);
        Gate::authorize('manageMachines', $workspace);

        if ($destination->machine !== null && ($reason = $queue->unavailable($destination->machine)) !== null) {
            throw ValidationException::withMessages(['machine_id' => $reason]);
        }
        $this->setUpReceiver($request, $destination, $keys, $queue);
        Activity::record($workspace, 'backup_destination.retry', $destination->name);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, BackupDestination $destination): RedirectResponse
    {
        abort_unless($destination->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        // Machines that sent here stop until they get a new destination.
        $destination->machines()->update(['backup_schedule' => 'off']);
        $destination->delete();
        Activity::record($workspace, 'backup_destination.remove', $destination->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$destination->name}. Backups already there are untouched."]);

        return back();
    }

    /**
     * Validate S3 settings and check them with a test upload. A blank
     * secret on edit keeps the saved one.
     *
     * @param  array<string, mixed>|null  $current
     * @return array{name: string, settings: array<string, mixed>}
     */
    private function s3(Request $request, S3Probe $probe, ?array $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'endpoint' => ['required', 'string', 'max:253'],
            'region' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/i'],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]+[a-z0-9]$/'],
            'access_key' => ['required', 'string', 'max:200'],
            'secret_key' => [$current === null ? 'required' : 'nullable', 'string', 'max:200'],
            'path_style' => ['boolean'],
            'prefix' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\/-]*$/'],
        ]);

        // People paste URLs; the daemon wants a bare host.
        $endpoint = strtolower(preg_replace('#^https?://#i', '', rtrim(trim($data['endpoint']), '/')));
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?::\d+)?$/', $endpoint)) {
            throw ValidationException::withMessages(['endpoint' => 'Use the storage endpoint\'s host, like s3.eu-west-2.amazonaws.com.']);
        }

        $settings = [
            'endpoint' => $endpoint,
            'region' => strtolower($data['region']),
            'bucket' => $data['bucket'],
            'access_key' => trim($data['access_key']),
            'secret_key' => filled($data['secret_key'] ?? null) ? trim($data['secret_key']) : ($current['secret_key'] ?? ''),
            'path_style' => (bool) ($data['path_style'] ?? false),
            'prefix' => trim((string) ($data['prefix'] ?? ''), '/'),
        ];

        if (($problem = $probe->check($settings)) !== null) {
            throw ValidationException::withMessages(['probe' => $problem]);
        }

        return ['name' => trim($data['name']), 'settings' => $settings];
    }

    private function setUpReceiver(Request $request, BackupDestination $destination, ReceiverKey $keys, DaemonJobQueue $queue): void
    {
        $pair = $keys->generate();
        $destination->forceFill([
            'private_key' => $pair['private'],
            'public_key' => $pair['public'],
            'host_key' => null,
            'status' => 'setting_up',
            'status_message' => null,
        ])->save();

        try {
            $queue->queue($destination->machine, $request->user(), 'backup_receiver', [
                'type' => 'backup_receiver',
                'public_key' => $pair['public'],
            ], null, 120);
        } catch (RuntimeException $e) {
            $destination->forceFill(['status' => 'failed', 'status_message' => $e->getMessage()])->save();
        }
    }
}
