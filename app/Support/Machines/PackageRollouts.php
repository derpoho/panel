<?php

namespace App\Support\Machines;

use App\Http\Controllers\MachineUpdatesController;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\PackageRollout;
use App\Models\PackageRolloutMachine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OS updates rolled out to many machines. A rollout installs the latest
 * of chosen packages (or everything waiting), or exact versions, on each
 * machine in turn: all at once, or in batches that wait for the one
 * before, stopping at a failure if asked, and restarting a machine whose
 * install needs it if asked. tick() (every minute) moves them along.
 *
 * Exact versions only make sense where a machine's package manager
 * offers them, so they're checked against each machine's last check.
 */
final class PackageRollouts
{
    /** Installing can take a while on a server that's far behind. */
    private const int INSTALL_TIMEOUT = 1800;

    /** How long alerts are held while a machine restarts. */
    private const int REBOOT_QUIET_MINUTES = 10;

    /** How long a restart may take before we stop waiting and say so. */
    private const int REBOOT_PATIENCE_MINUTES = 15;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * What a rollout takes, for the panel and MCP alike.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'packages' => ['sometimes', 'array', 'max:500'],
            'packages.*' => ['array'],
            'packages.*.name' => ['required', 'string', 'max:200', 'regex:'.PackageUpdates::NAME_PATTERN],
            'packages.*.version' => ['nullable', 'string', 'max:128', 'regex:'.PackageUpdates::VERSION_PATTERN],
            'mode' => ['required', 'in:'.implode(',', PackageRollout::MODES)],
            'allow_downgrade' => ['sometimes', 'boolean'],
            'machines' => ['sometimes', 'nullable', 'array', 'max:2000'],
            'machines.*' => ['integer'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'stop_on_failure' => ['sometimes', 'boolean'],
            'reboot' => ['sometimes', 'in:'.implode(',', PackageRollout::REBOOTS)],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(60)->toIso8601String()],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'packages.*.name.regex' => 'Package names are letters, digits and + . _ : ~ -, and can’t start with a dash.',
            'packages.*.version.regex' => 'Versions are letters, digits and . + ~ : _ -, and can’t start with a dash.',
            'scheduled_for.after' => 'Pick a time in the future.',
            'scheduled_for.before' => 'Pick a time within the next 60 days.',
        ];
    }

    /**
     * Plan a rollout, and start it now unless it's for later.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(Workspace $workspace, User $by, array $input): PackageRollout
    {
        ['data' => $data, 'packages' => $packages, 'machines' => $machines, 'everything' => $everything] = $this->plan($workspace, $input);

        $rollout = DB::transaction(function () use ($workspace, $by, $data, $packages, $machines, $everything) {
            $rollout = PackageRollout::query()->create([
                'workspace_id' => $workspace->id,
                'created_by' => $by->id,
                'name' => filled($data['name'] ?? null) ? trim((string) $data['name']) : self::title($packages, $everything),
                'packages' => $packages,
                'mode' => $data['mode'],
                'allow_downgrade' => $data['mode'] === 'exact' && (bool) ($data['allow_downgrade'] ?? false),
                'batch_size' => $data['batch_size'] ?? null,
                'stop_on_failure' => (bool) ($data['stop_on_failure'] ?? true),
                'reboot' => $data['reboot'] ?? 'never',
                'scheduled_for' => isset($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : null,
                'state' => 'pending',
            ]);
            foreach ($machines->values() as $position => $machine) {
                PackageRolloutMachine::query()->create([
                    'package_rollout_id' => $rollout->id,
                    'machine_id' => $machine->id,
                    'position' => $position,
                    'state' => 'pending',
                ]);
            }

            return $rollout;
        });

        Activity::record($workspace, 'updates.rollout', $rollout->name, null, $by, array_filter([
            'machines' => $machines->count(),
            'packages' => $everything ? null : count($packages),
            'mode' => $rollout->mode,
            'batch' => $rollout->batch_size,
            'reboot' => $rollout->reboot === 'if_required' ? true : null,
            'scheduled_for' => $rollout->scheduled_for?->toIso8601String(),
        ], fn ($v) => $v !== null));

        if ($rollout->scheduled_for === null) {
            $this->advance($rollout);
        }

        return $rollout->fresh() ?? $rollout;
    }

    /**
     * Check a rollout without starting it: the packages and the machines
     * it would run on.
     *
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, mixed>, packages: list<array{name: string, version: string|null}>, machines: Collection<int, Machine>, everything: bool}
     *
     * @throws ValidationException
     */
    public function plan(Workspace $workspace, array $input): array
    {
        $data = Validator::make($input, self::rules(), self::messages())->validate();
        $everything = (bool) ($data['all'] ?? false);
        $exact = $data['mode'] === 'exact';

        $chosen = [];
        foreach (is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
            $name = (string) $package['name'];
            $chosen[$name] ??= ['name' => $name, 'version' => $exact && is_string($package['version'] ?? null) && $package['version'] !== '' ? $package['version'] : null];
        }
        $packages = array_values($chosen);
        if ($everything && ($exact || $packages !== [])) {
            throw ValidationException::withMessages(['packages' => 'Every waiting update goes to the latest versions: leave packages out.']);
        }
        if (! $everything && $packages === []) {
            throw ValidationException::withMessages(['packages' => 'Pick at least one package.']);
        }
        $unversioned = array_column(array_filter($packages, fn (array $p) => $p['version'] === null), 'name');
        if ($exact && $unversioned !== []) {
            throw ValidationException::withMessages(['packages' => 'Pick a version for '.self::list($unversioned).'.']);
        }

        $machines = $this->machines($workspace, $data['machines'] ?? null, $everything, array_column($packages, 'name'));
        if ($exact) {
            $this->checkVersions($machines, $packages);
        }

        return ['data' => $data, 'packages' => $packages, 'machines' => $machines, 'everything' => $everything];
    }

    /**
     * The machines it's for: those chosen (all in this workspace), or
     * every machine with one of the packages waiting.
     *
     * @param  list<int>|null  $ids
     * @param  array<int, string>  $names
     * @return Collection<int, Machine>
     */
    private function machines(Workspace $workspace, ?array $ids, bool $everything, array $names): Collection
    {
        $query = $workspace->machines()->whereNotNull('uid')->orderBy('name');
        if ($ids !== null && $ids !== []) {
            $machines = (clone $query)->whereIn('id', array_unique($ids))->get();
            if ($machines->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['machines' => 'Some of those machines aren’t in this workspace, or have no daemon.']);
            }

            return $machines;
        }

        $machines = $everything
            ? (clone $query)->where('updates_count', '>', 0)->get()
            : (clone $query)->whereIn('id', MachinePackageUpdate::query()->whereIn('name', $names)->select('machine_id'))->get();
        if ($machines->isEmpty()) {
            throw ValidationException::withMessages(['machines' => 'No machine has these updates waiting.']);
        }

        return $machines;
    }

    /**
     * Each machine's last check must offer each exact version, or have it
     * installed already. Says which machines don't.
     *
     * @param  Collection<int, Machine>  $machines
     * @param  list<array{name: string, version: string|null}>  $packages
     *
     * @throws ValidationException
     */
    private function checkVersions(Collection $machines, array $packages): void
    {
        $rows = MachinePackageUpdate::query()
            ->whereIn('machine_id', $machines->pluck('id'))
            ->whereIn('name', array_column($packages, 'name'))
            ->get(['machine_id', 'name', 'installed', 'candidate'])
            ->groupBy(fn (MachinePackageUpdate $row) => $row->machine_id.'|'.$row->name);

        $problems = [];
        foreach ($packages as $package) {
            $without = $machines->reject(fn (Machine $machine) => ($rows->get($machine->id.'|'.$package['name']) ?? collect())
                ->contains(fn (MachinePackageUpdate $row) => $row->candidate === $package['version'] || $row->installed === $package['version']));
            if ($without->isNotEmpty()) {
                $problems[] = "{$package['name']} {$package['version']} isn’t offered on ".self::list($without->map(fn (Machine $m) => $m->name)->all()).'.';
            }
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['packages' => implode(' ', $problems).' Exact versions come from what each machine’s last check found, so pick machines on the same OS release.']);
        }
    }

    /**
     * Call it off: nothing more is started. Installs already running
     * finish, without a restart after.
     */
    public function cancel(PackageRollout $rollout, User $by): void
    {
        if (! in_array($rollout->state, PackageRollout::OPEN, true)) {
            return;
        }
        DB::transaction(function () use ($rollout) {
            $rollout->forceFill(['state' => 'cancelled', 'finished_at' => now()])->save();
            $rollout->targets()->where('state', 'pending')->update(['state' => 'skipped', 'outcome' => 'Cancelled before its turn.', 'finished_at' => now()]);
        });
        Activity::record($rollout->workspace_id, 'updates.rollout_cancel', $rollout->name, null, $by);
    }

    /**
     * Start what's due and move everything running along. Returns how
     * many rollouts it looked at.
     */
    public function tick(): int
    {
        $count = 0;
        PackageRollout::query()
            ->where(fn ($q) => $q->where('state', 'running')
                ->orWhere(fn ($q) => $q->where('state', 'pending')->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))))
            ->orderBy('id')
            ->each(function (PackageRollout $rollout) use (&$count) {
                $this->advance($rollout);
                $count++;
            });

        // Cancelled, with installs still running: see them through.
        PackageRolloutMachine::query()->where('state', 'running')
            ->whereHas('rollout', fn ($q) => $q->where('state', 'cancelled'))
            ->with(['rollout', 'machine'])
            ->each(fn (PackageRolloutMachine $target) => $this->progress($target, $target->rollout));

        return $count;
    }

    /** Move one rollout along: the machines running, then the next batch. */
    public function advance(PackageRollout $rollout): void
    {
        if ($rollout->state === 'pending') {
            if ($rollout->scheduled_for !== null && $rollout->scheduled_for->isFuture()) {
                return;
            }
            $rollout->forceFill(['state' => 'running', 'started_at' => now()])->save();
        }
        if ($rollout->state !== 'running') {
            return;
        }

        $targets = $rollout->targets()->with('machine')->get();
        foreach ($targets->where('state', 'running') as $target) {
            $this->progress($target, $rollout);
        }
        if ($targets->contains('state', 'running')) {
            return;
        }

        if ($rollout->stop_on_failure && ($failed = $targets->firstWhere('state', 'failed')) !== null) {
            $rollout->targets()->where('state', 'pending')->update([
                'state' => 'skipped',
                'outcome' => 'Not started: the rollout stopped after '.$failed->machine->name.' failed.',
                'finished_at' => now(),
            ]);
            $this->finish($rollout);

            return;
        }

        // The next batch. One that's all skipped (offline, nothing to do)
        // makes way for the one after straight away.
        while (true) {
            $pending = $targets->where('state', 'pending')->values();
            if ($pending->isEmpty()) {
                $this->finish($rollout);

                return;
            }
            $busy = false;
            foreach ($pending->take($rollout->batch_size ?? $pending->count()) as $target) {
                $busy = ! in_array($this->start($target, $rollout), ['done', 'skipped'], true) || $busy;
            }
            if ($busy) {
                return;
            }
        }
    }

    /**
     * Start one machine's install. Returns its state after: running,
     * waiting (another install is running there), done (nothing to do),
     * or skipped.
     */
    private function start(PackageRolloutMachine $target, PackageRollout $rollout): string
    {
        $machine = $target->machine;
        $user = $rollout->creator;
        $skip = fn (string $why) => $this->settle($target, 'skipped', $why);

        if ($user === null) {
            return $skip('Whoever started the rollout has left the workspace, so nothing was run.');
        }
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            return $skip($reason);
        }
        if (PackageUpdates::installing($machine) !== null) {
            return 'waiting';
        }

        $waiting = MachinePackageUpdate::query()->where('machine_id', $machine->id)->get(['name', 'installed', 'candidate']);
        if ($rollout->mode === 'exact') {
            if (! DaemonJobQueue::canInstallVersions($machine)) {
                return $skip('Daemon too old for exact versions. Update it first with “sudo serverosd update”.');
            }
            $install = [];
            $gone = [];
            foreach ($rollout->packages as $package) {
                $rows = $waiting->where('name', $package['name']);
                if ($rows->contains('candidate', $package['version'])) {
                    $install[] = ['name' => $package['name'], 'version' => (string) $package['version']];
                } elseif (! $rows->contains('installed', $package['version'])) {
                    $gone[] = $package['name'].' '.$package['version'];
                }
            }
            if ($install === []) {
                return $gone === []
                    ? $this->settle($target, 'done', 'Already on these versions.')
                    : $skip(self::list($gone).' isn’t offered here any more.');
            }
            [$kind, $job] = ['package_install_versions', ['type' => 'package_install_versions', 'packages' => $install, 'allow_downgrade' => $rollout->allow_downgrade]];
        } elseif ($rollout->everything()) {
            $install = [];
            [$kind, $job] = ['package_updates', ['type' => 'package_updates', 'apply' => true, 'security_only' => false]];
        } else {
            $names = array_values(array_intersect(array_column($rollout->packages, 'name'), $waiting->pluck('name')->all()));
            if ($names === []) {
                return $skip('None of these updates were waiting here.');
            }
            if (! MachineUpdatesController::canPick($machine)) {
                return $skip('Daemon too old to install chosen packages. Update it first with “sudo serverosd update”.');
            }
            $install = array_map(fn (string $name) => ['name' => $name, 'version' => null], $names);
            [$kind, $job] = ['package_install', ['type' => 'package_install', 'packages' => $names]];
        }

        try {
            $record = $this->queue->queue($machine, $user, $kind, $job, null, self::INSTALL_TIMEOUT);
        } catch (RuntimeException $e) {
            report($e);

            return $this->settle($target, 'failed', 'Couldn’t start the install: '.$e->getMessage());
        }
        $target->forceFill(['state' => 'running', 'job_id' => $record->id, 'started_at' => now(), 'installed' => $install, 'outcome' => null])->save();

        return 'running';
    }

    /** Follow a machine's install, then its restart. */
    private function progress(PackageRolloutMachine $target, PackageRollout $rollout): void
    {
        $machine = $target->machine;

        if ($target->reboot_job_id !== null) {
            $reboot = DaemonJob::query()->find($target->reboot_job_id);
            if ($reboot === null || in_array($reboot->state, ['failed', 'refused', 'cancelled'], true)) {
                $this->settle($target, 'failed', 'Installed, but the restart didn’t start: '.(self::error($reboot) ?: 'the daemon refused it').'.');
            } elseif ($machine->isOnline() && $machine->connected_at !== null && $machine->connected_at->gt($reboot->created_at)) {
                $this->settle($target, 'done', 'Installed, and back after a restart.');
            } elseif ($reboot->created_at->lt(now()->subMinutes(self::REBOOT_PATIENCE_MINUTES))) {
                $this->settle($target, 'failed', 'Installed, but not back '.self::REBOOT_PATIENCE_MINUTES.' minutes after the restart. Check it in your provider’s console.');
            }

            return;
        }

        $job = $target->job_id === null ? null : DaemonJob::query()->find($target->job_id);
        if ($job === null) {
            $this->settle($target, 'failed', 'The install went missing.');

            return;
        }
        if ($job->isStale()) {
            $this->settle($target, 'failed', 'The machine stopped answering during the install.');

            return;
        }
        if (in_array($job->state, ['failed', 'timed_out', 'refused', 'cancelled'], true)) {
            $this->settle($target, 'failed', 'The install didn’t finish: '.(self::error($job) ?: str_replace('_', ' ', $job->state)).'.');

            return;
        }
        if ($job->state !== 'succeeded') {
            return;
        }

        $result = is_array($job->result) ? $job->result : [];
        if (is_array($result['installed'] ?? null)) {
            $target->installed = array_values(array_filter(array_map(
                fn ($p) => is_array($p) && is_string($p['name'] ?? null) ? ['name' => $p['name'], 'version' => is_string($p['version'] ?? null) ? $p['version'] : null] : null,
                $result['installed'],
            )));
        }
        $needsRestart = (bool) ($result['reboot_required'] ?? false);
        if (! $needsRestart) {
            $this->settle($target, 'done', 'Installed.');

            return;
        }
        if ($rollout->reboot !== 'if_required') {
            $this->settle($target, 'done', 'Installed. A restart is needed to finish.');

            return;
        }
        if ($rollout->state !== 'running') {
            $this->settle($target, 'done', 'Installed. Not restarted, as the rollout was cancelled.');

            return;
        }

        $reason = $rollout->creator === null ? 'whoever started the rollout has left the workspace' : $this->queue->unavailable($machine);
        if ($reason === null) {
            try {
                $reboot = $this->queue->queue($machine, $rollout->creator, 'reboot', ['type' => 'reboot'], null, 60);
                if (! $machine->inMaintenance()) {
                    $machine->forceFill([
                        'maintenance_until' => now()->addMinutes(self::REBOOT_QUIET_MINUTES),
                        'maintenance_reason' => 'Restarting after updates',
                        'maintenance_public' => false,
                    ])->save();
                }
                Activity::record($rollout->workspace_id, 'machine.reboot', $machine->name, $machine, $rollout->creator);
                $target->forceFill(['reboot_job_id' => $reboot->id, 'outcome' => 'Installed. Restarting.'])->save();

                return;
            } catch (RuntimeException $e) {
                report($e);
                $reason = $e->getMessage();
            }
        }
        $this->settle($target, 'done', 'Installed. A restart is needed, but it couldn’t be sent: '.rtrim($reason, '.').'.');
    }

    private function settle(PackageRolloutMachine $target, string $state, string $outcome): string
    {
        $target->forceFill(['state' => $state, 'outcome' => $outcome, 'finished_at' => now()])->save();

        return $state;
    }

    /** Over: done, or failed when any machine failed. */
    private function finish(PackageRollout $rollout): void
    {
        $counts = $rollout->targets()->reorder()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
        $failed = (int) ($counts['failed'] ?? 0);
        $rollout->forceFill(['state' => $failed > 0 ? 'failed' : 'done', 'finished_at' => now()])->save();

        Activity::system($rollout->workspace_id, 'updates.rollout_done', $rollout->name, null, [
            'state' => $rollout->state,
            'done' => (int) ($counts['done'] ?? 0),
            'failed' => $failed,
            'skipped' => (int) ($counts['skipped'] ?? 0),
        ]);
    }

    /**
     * A rollout for a list: its plan and how far it has got.
     *
     * @return array<string, mixed>
     */
    public static function summary(PackageRollout $rollout): array
    {
        $counts = $rollout->relationLoaded('targets')
            ? $rollout->targets->countBy('state')
            : $rollout->targets()->reorder()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
        $total = (int) $counts->sum();

        return [
            'id' => $rollout->id,
            'name' => $rollout->name,
            'state' => $rollout->state,
            'mode' => $rollout->mode,
            'everything' => $rollout->everything(),
            'packages' => $rollout->packages,
            'allowDowngrade' => $rollout->allow_downgrade,
            'batchSize' => $rollout->batch_size,
            'stopOnFailure' => $rollout->stop_on_failure,
            'reboot' => $rollout->reboot,
            'scheduledFor' => $rollout->scheduled_for?->toIso8601String(),
            'startedAt' => $rollout->started_at?->toIso8601String(),
            'finishedAt' => $rollout->finished_at?->toIso8601String(),
            'createdAt' => $rollout->created_at?->toIso8601String(),
            'by' => $rollout->creator?->name,
            'total' => $total,
            'finished' => (int) (($counts['done'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['skipped'] ?? 0)),
            'done' => (int) ($counts['done'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
        ];
    }

    /**
     * A rollout with each machine's part in it.
     *
     * @return array<string, mixed>
     */
    public static function detail(PackageRollout $rollout): array
    {
        $rollout->load(['targets.machine', 'creator']);

        return [
            ...self::summary($rollout),
            'machines' => $rollout->targets->map(fn (PackageRolloutMachine $t) => [
                'machineId' => $t->machine_id,
                'name' => $t->machine->name,
                'provider' => $t->machine->provider,
                'state' => $t->state,
                'outcome' => $t->outcome,
                'installed' => $t->installed ?? [],
                'jobId' => $t->job_id,
                'rebootJobId' => $t->reboot_job_id,
                'startedAt' => $t->started_at?->toIso8601String(),
                'finishedAt' => $t->finished_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * A name for it from what it installs: "openssl", "openssl and curl",
     * "openssl, curl and 3 more".
     *
     * @param  list<array{name: string, version: string|null}>  $packages
     */
    private static function title(array $packages, bool $everything): string
    {
        if ($everything) {
            return 'Every waiting update';
        }
        $names = array_map(fn (array $p) => $p['name'].(count($packages) === 1 && $p['version'] !== null ? ' '.$p['version'] : ''), $packages);
        if (count($names) > 3) {
            return implode(', ', array_slice($names, 0, 2)).' and '.(count($names) - 2).' more';
        }

        return self::list($names);
    }

    /** @param  array<int, string>  $items */
    private static function list(array $items): string
    {
        $items = array_values($items);
        $shown = array_slice($items, 0, 5);
        $rest = count($items) - count($shown);
        if ($rest > 0) {
            return implode(', ', $shown).' and '.$rest.' more';
        }
        $last = array_pop($shown);

        return $shown === [] ? (string) $last : implode(', ', $shown).' and '.$last;
    }

    private static function error(?DaemonJob $job): string
    {
        return $job !== null && is_array($job->error) ? rtrim(trim((string) ($job->error['message'] ?? '')), '.') : '';
    }
}
