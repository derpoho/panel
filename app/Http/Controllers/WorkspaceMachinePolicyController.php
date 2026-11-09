<?php

namespace App\Http\Controllers;

use App\Jobs\ClearMachineData;
use App\Models\Activity;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\DaemonUpdates;
use App\Support\Machines\MachineDataEraser;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Settings › Machine policies: what the workspace's machines collect and
 * send, as named sets a machine follows by itself, through its group, by
 * its metadata (a policy's rules), or as the default. Saving one sends it
 * to every machine that follows it.
 */
class WorkspaceMachinePolicyController extends Controller
{
    public function show(Workspace $workspace): Response
    {
        $default = MachinePolicies::defaultFor($workspace);
        $policies = $workspace->machinePolicies()->orderByDesc('is_default')->orderBy('name')->get();
        $groups = $workspace->machineGroups()->orderBy('name')->get();
        $assigned = MachinePolicies::effective($workspace);
        $machines = $workspace->machines()->get(['id', 'name', 'daemon_version', 'policy_pending', 'facts', 'workspace_id', 'machine_policy_id', 'machine_group_id'])->keyBy('id');

        return Inertia::render('workspace/settings/MachinePolicies', [
            'workspace' => $workspace->toPageProps(),
            'canManage' => Gate::allows('manageMachines', $workspace),
            'defaultPolicyId' => $default->id,
            'policies' => $policies->map(function (MachinePolicy $policy) use ($groups, $assigned, $machines) {
                $following = $machines->only(array_keys(array_filter($assigned, fn (int $id) => $id === $policy->id)));
                $behind = $policy->updates === 'automatic' ? collect() : $following->filter(fn (Machine $machine) => DaemonUpdates::available($machine) !== null);

                return [
                    ...$policy->toPageProps(),
                    'machines' => $following->count(),
                    'groups' => $groups->where('machine_policy_id', $policy->id)->pluck('name')->values(),
                    'pending' => $following->where('policy_pending', true)->count(),
                    // Machines whose daemon can't yet turn discovery or logs
                    // off, or wait for updates: it updates itself.
                    'outdated' => $following->reject(fn (Machine $machine) => DaemonJobQueue::canFollowPolicy($machine))->pluck('name')->values(),
                    // Waiting for someone to install an update (or the pin).
                    'updatable' => $behind->count(),
                ];
            })->values(),
            // What a policy's rules can match, and the releases it can pin.
            'metadata' => MachineMetadata::inUse($workspace),
            'releases' => DaemonRelease::query()->where('channel', 'stable')->get(['version'])->pluck('version')->unique()
                ->sort(fn (string $a, string $b) => DaemonRelease::compare($b, $a))->values()->take(20),
            'since' => 'v'.config('daemon.policy_since'),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $this->validated($request, $workspace);

        // Rules can take machines over from the default.
        $policy = MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'),
            fn () => $workspace->machinePolicies()->create([...$data['settings'], 'name' => $data['name']]));
        Activity::record($workspace, 'machine_policy.create', $policy->name);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, MachinePolicy $policy): RedirectResponse
    {
        abort_unless($policy->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $this->validated($request, $workspace, $policy);

        $policy->fill([...$data['settings'], 'name' => $data['name']]);
        if (! $policy->isDirty()) {
            AuditContext::nothingChanged();

            return back();
        }
        // Narrowing what's sent (logs off, or masking on) can delete what
        // its machines already sent, if asked.
        $narrowed = ($policy->isDirty('logs') && ! $policy->logs)
            || ($policy->logs && $policy->isDirty('log_ip_masking') && $policy->getOriginal('log_ip_masking') === 'off');
        $settingsChanged = $policy->isDirty(['discovery', 'logs', 'log_ip_masking', 'security_watch', 'error_reports', 'updates', 'pinned_version']);

        // New rules move machines on or off it; they're sent theirs. Then
        // everything that follows it gets the new settings.
        MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'), fn () => $policy->save());
        $machines = MachinePolicies::following($policy)->pluck('id');
        if ($settingsChanged) {
            MachinePolicies::push($machines);
        }
        Activity::record($workspace, 'machine_policy.update', $policy->name, meta: ['machines' => $machines->count()]);

        if ($narrowed && $data['clear_logs']) {
            foreach ($machines as $id) {
                ClearMachineData::dispatch($id, MachineDataEraser::forFeature('logs'), $request->user()->id, rescan: false)->afterCommit();
            }
        }

        return back();
    }

    /** The policy machines follow when nothing else picks one. */
    #[Audited]
    public function makeDefault(Workspace $workspace, MachinePolicy $policy): RedirectResponse
    {
        abort_unless($policy->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($policy->is_default) {
            AuditContext::nothingChanged();

            return back();
        }

        MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'), fn () => DB::transaction(function () use ($workspace, $policy) {
            $workspace->machinePolicies()->where('is_default', true)->update(['is_default' => false]);
            // The default catches everything else, so it has no rules.
            $policy->forceFill(['is_default' => true, 'applies_to' => null])->save();
        }));
        Activity::record($workspace, 'machine_policy.default', $policy->name);

        return back();
    }

    /** Machines and groups that picked it go back to the default. */
    #[Audited]
    public function destroy(Workspace $workspace, MachinePolicy $policy): RedirectResponse
    {
        abort_unless($policy->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($policy->is_default) {
            return back()->withErrors(['policy' => __('Make another policy the default first.')]);
        }

        MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'), fn () => $policy->delete());
        Activity::record($workspace, 'machine_policy.delete', $policy->name);

        return back();
    }

    /**
     * Install the waiting update (or the pinned release) on every machine
     * that follows this policy: approving it for all of them at once.
     */
    #[Audited]
    public function installUpdates(Workspace $workspace, MachinePolicy $policy, DaemonUpdates $updates): RedirectResponse
    {
        abort_unless($policy->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        [$sent, $missed] = [0, []];
        foreach (MachinePolicies::following($policy)->get() as $machine) {
            if (DaemonUpdates::available($machine) === null) {
                continue;
            }
            try {
                $release = $updates->install($machine);
                Activity::record($workspace, 'machine.daemon_update', $machine->name, $machine, meta: ['from' => $machine->daemon_version, 'to' => $release->version]);
                $sent++;
            } catch (RuntimeException) {
                $missed[] = $machine->name;
            }
        }
        if ($sent === 0 && $missed === []) {
            throw ValidationException::withMessages(['update' => __('Every machine that follows it is up to date.')]);
        }
        Inertia::flash('toast', ['type' => $missed === [] ? 'success' : 'warning', 'message' => $missed === []
            ? trans_choice('{1} Updating 1 machine. It reconnects by itself in a minute or so.|[2,*] Updating :count machines. They reconnect by themselves in a minute or so.', $sent, ['count' => $sent])
            : __('Updating :sent. Couldn’t reach :names: try again once they’re online.', ['sent' => $sent, 'names' => implode(', ', $missed)])]);

        return back();
    }

    /**
     * @return array{name: string, settings: array<string, mixed>, clear_logs: bool}
     */
    private function validated(Request $request, Workspace $workspace, ?MachinePolicy $policy = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('machine_policies', 'name')->where('workspace_id', $workspace->id)->ignore($policy?->id)],
            'discovery' => ['required', 'boolean'],
            'logs' => ['required', 'boolean'],
            'log_ip_masking' => ['required', Rule::in(MachinePolicy::MASKING)],
            'security_watch' => ['required', 'boolean'],
            'error_reports' => ['sometimes', 'boolean'],
            'status_page_new' => ['required', 'boolean'],
            'updates' => ['required', Rule::in(MachinePolicy::UPDATES)],
            'pinned_version' => ['nullable', 'required_if:updates,pinned', 'string', Rule::exists('daemon_releases', 'version')->where('channel', 'stable')],
            'applies_to' => ['sometimes', 'nullable', 'array', 'max:10'],
            'applies_to.*.field' => ['required', 'string', 'max:50', fn ($attribute, $value, $fail) => MachineMetadata::validField((string) $value) || $fail(__('Pick provider, region, tier or one of your labels.'))],
            'applies_to.*.value' => ['required', 'string', 'max:'.MachineMetadata::MAX_VALUE],
            'clear_logs' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => __('There’s already a policy with that name.'),
            'pinned_version.required_if' => __('Pick the version to stay on.'),
            'pinned_version.exists' => __('That isn’t a released version.'),
        ]);

        /** @var list<array{field: string, value: string}> $given validated above */
        $given = $data['applies_to'] ?? [];
        $rules = collect($given)
            ->map(fn (array $rule) => ['field' => (string) $rule['field'], 'value' => trim((string) $rule['value'])])
            ->unique(fn (array $rule) => $rule['field'].'='.mb_strtolower($rule['value']))->values()->all();

        return [
            'name' => trim($data['name']),
            'settings' => [
                'discovery' => (bool) $data['discovery'],
                'logs' => (bool) $data['logs'],
                'log_ip_masking' => $data['log_ip_masking'],
                'security_watch' => (bool) $data['security_watch'],
                'error_reports' => (bool) ($data['error_reports'] ?? $policy->error_reports ?? true),
                'status_page_new' => (bool) $data['status_page_new'],
                'updates' => $data['updates'],
                'pinned_version' => $data['updates'] === 'pinned' ? $data['pinned_version'] : null,
                // The default catches everything else: it has no rules.
                'applies_to' => ($policy->is_default ?? false) || $rules === [] ? null : $rules,
            ],
            'clear_logs' => (bool) ($data['clear_logs'] ?? false),
        ];
    }
}
