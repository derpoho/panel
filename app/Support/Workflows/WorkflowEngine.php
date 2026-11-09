<?php

namespace App\Support\Workflows;

use App\Enums\WorkspacePermission;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Notifications\WorkflowMessage;
use App\Support\Audit\AuditContext;
use App\Support\Backups\BackupRunner;
use App\Support\DaemonJobQueue;
use App\Support\Extensions\Extensions;
use App\Support\Monitors\HostResolver;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use App\Support\Projects\Environments;
use App\Support\Projects\ProjectData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Runs workflows. A run starts at the trigger and follows the edges out of
 * each step by how it ended: "out" when it worked, "failed" when it didn't,
 * "true"/"false" from a branch. Steps that send work to a server wait for
 * the daemon to report back; waits and monitor checks are picked up again
 * by the tick. Each step runs at most once per run, so a loop drawn on the
 * canvas can't spin forever.
 *
 * @phpstan-type StepResult array{state: string, message?: string, port?: string, jobs?: list<string>, until?: int, deadline?: int, timeout?: int, summary?: string, pr?: string|null, model?: string|null}
 */
class WorkflowEngine
{
    /** Steps one run may take. */
    public const MAX_STEPS = 50;

    /** A server job that hasn't reported back in this long has failed. */
    public const JOB_TIMEOUT_MINUTES = 45;

    public const LOG_LIMIT = 400;

    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly MonitorRunner $monitors,
        private readonly MonitorAlerts $alerts,
        private readonly BackupRunner $backups,
        private readonly HostResolver $resolver,
        private readonly ProjectData $data,
        private readonly Environments $environments,
    ) {}

    /**
     * Start a run. Null when the workflow can't run now (no plan, or it
     * has run too often), with the reason logged.
     *
     * @param  array<string, mixed>  $data  what the trigger was about
     */
    public function start(Workflow $workflow, string $kind, string $summary, array $data = [], ?User $user = null, bool $limited = true): ?WorkflowRun
    {
        $workspace = $workflow->workspace;
        if (! $workspace->hasAutomations()) {
            return null;
        }
        if ($limited && $workflow->runs()->where('started_at', '>=', now()->subHour())->count() >= Workflow::RUNS_PER_HOUR) {
            Log::info('Workflow run skipped: hourly limit', ['workflow' => $workflow->id]);

            return null;
        }

        $trigger = $workflow->triggerNode();
        $run = $workflow->runs()->create([
            'user_id' => $user?->id,
            'impersonation_session_id' => AuditContext::viewAsSession($user),
            'state' => 'running',
            'trigger' => ['kind' => $kind, 'summary' => mb_substr($summary, 0, 200), 'data' => $data],
            'graph' => $workflow->graph,
            'steps' => [],
            'frontier' => $trigger ? [$trigger['id']] : [],
            'logs' => [],
            'started_at' => now(),
        ]);
        $workflow->forceFill(['last_run_at' => now()])->saveQuietly();

        $this->log($run, null, 'info', "Run #{$run->id} started: {$summary}");
        Activity::record($workspace, 'workflow.run', $workflow->name, null, $user, ['run' => $run->id, 'trigger' => $kind]);

        $this->advance($run);

        return $run->refresh();
    }

    /**
     * Run every step that's ready, then settle the run's state.
     */
    public function advance(WorkflowRun $run): void
    {
        DB::transaction(function () use ($run) {
            /** @var WorkflowRun $locked */
            $locked = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $run->setRawAttributes($locked->getAttributes(), true);
            if ($run->finished()) {
                return;
            }

            $taken = 0;
            while (($id = $this->shift($run)) !== null && $taken < self::MAX_STEPS) {
                $steps = $run->steps;
                if (isset($steps[$id]['state']) && $steps[$id]['state'] !== 'pending') {
                    continue;
                }
                $node = $this->node($run, $id);
                if ($node === null) {
                    continue;
                }
                $taken++;

                $steps[$id] = ['state' => 'running', 'startedAt' => now()->getTimestamp(), ...($steps[$id] ?? [])];
                $steps[$id]['state'] = 'running';
                $run->steps = $steps;

                try {
                    $result = $this->execute($run, $node);
                } catch (Throwable $e) {
                    report($e);
                    $result = ['state' => 'failed', 'message' => 'Something went wrong running this step.'];
                }
                $this->settle($run, $node, $result);
            }

            if (count($run->steps) >= self::MAX_STEPS && $run->frontier !== []) {
                $this->log($run, null, 'warning', 'Stopped after '.self::MAX_STEPS.' steps.');
                $run->frontier = [];
            }

            $this->finishIfDone($run);
            $run->save();
        });
    }

    /**
     * A server job a step was waiting on has finished.
     */
    public function jobFinished(DaemonJob $job): void
    {
        $run = $job->workflow_run_id ? WorkflowRun::query()->find($job->workflow_run_id) : null;
        if ($run === null || $run->finished() || $job->workflow_node === null) {
            return;
        }

        DB::transaction(function () use ($run, $job) {
            $locked = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $run->setRawAttributes($locked->getAttributes(), true);
            $step = $run->steps[$job->workflow_node] ?? null;
            $node = $this->node($run, $job->workflow_node);
            if ($step === null || ($step['state'] ?? null) !== 'running' || $node === null) {
                return;
            }

            $jobs = DaemonJob::query()->whereIn('id', $step['jobs'] ?? [])->get();
            if ($jobs->contains(fn (DaemonJob $j) => ! in_array($j->state, ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'], true))) {
                return;
            }

            // A command (or a copy's script) can fail while the job itself
            // finishes: its exit code says.
            $copyFailed = fn (DaemonJob $j) => in_array($node['kind'], ['copy_data', 'command', 'agent'], true) && (int) ($j->result['exit_code'] ?? 0) !== 0;
            $failed = $jobs->first(fn (DaemonJob $j) => $j->state !== 'succeeded' || $copyFailed($j));
            if ($node['kind'] === 'agent') {
                $this->settle($run, $node, $this->agentFinished($run, $node, $jobs->first(), $failed !== null));
                $run->save();

                return;
            }
            foreach ($jobs as $j) {
                foreach (array_slice($j->log ?? [], -8) as $line) {
                    $this->log($run, $node['id'], 'info', mb_substr((string) $line, 0, 300));
                }
            }
            $result = $failed
                ? ['state' => 'failed', 'message' => $failed->state === 'succeeded' && $copyFailed($failed)
                    ? ($node['kind'] === 'command' ? 'The command' : 'A copy').' stopped (exit code '.(int) $failed->result['exit_code'].'). Its log on the machine’s page says why.'
                    : $this->jobError($failed)]
                : ['state' => 'succeeded', 'message' => $this->jobSuccess($node, $jobs->first())];
            // An atomic release keeps the one before it: say which, so the
            // run can switch back to it.
            $done = $jobs->first()->result ?? [];
            if (! $failed && $node['kind'] === 'release' && isset($done['release'], $done['previous']) && $done['previous'] !== ($done['commit'] ?? null)) {
                $result['rollbackTo'] = (string) $done['previous'];
            }
            $this->settle($run, $node, $result);
            $run->save();
        });

        $this->advance($run);
    }

    /**
     * Resume what's due: waits that are over, monitor checks to repeat,
     * server jobs that never reported back.
     *
     * @return int runs moved on
     */
    public function tick(): int
    {
        $moved = 0;

        WorkflowRun::query()
            ->where('state', 'running')
            ->where(fn ($q) => $q->whereNull('wake_at')->orWhere('wake_at', '<=', now()))
            ->where('started_at', '<=', now()->subSeconds(5))
            ->limit(200)
            ->get()
            ->each(function (WorkflowRun $run) use (&$moved) {
                $changed = false;
                foreach ($run->steps as $id => $step) {
                    $node = $this->node($run, $id);
                    if ($node === null) {
                        continue;
                    }
                    if (($step['state'] ?? null) === 'waiting' && ($step['until'] ?? 0) <= now()->getTimestamp()) {
                        $this->resume($run, $node);
                        $changed = true;
                    } elseif (($step['state'] ?? null) === 'running' && ($step['startedAt'] ?? 0) < now()->subMinutes($minutes = (int) ($step['timeout'] ?? self::JOB_TIMEOUT_MINUTES))->getTimestamp()) {
                        $this->settle($run, $node, ['state' => 'failed', 'message' => "The machine didn’t report back within {$minutes} minutes."]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $run->save();
                    $this->advance($run);
                    $moved++;
                }
            });

        return $moved;
    }

    public function cancel(WorkflowRun $run, User $user): void
    {
        if ($run->finished()) {
            AuditContext::nothingChanged();

            return;
        }
        $steps = $run->steps;
        foreach ($steps as $id => $step) {
            if (in_array($step['state'] ?? null, ['running', 'waiting', 'pending'], true)) {
                $steps[$id]['state'] = 'cancelled';
                $steps[$id]['finishedAt'] = now()->getTimestamp();
            }
        }
        $run->steps = $steps;
        $run->frontier = [];
        $by = AuditContext::viewAsSession($user) === null ? $user->name : AuditContext::SUPPORT;
        $this->log($run, null, 'warning', "Cancelled by {$by}.");
        $run->forceFill(['state' => 'cancelled', 'finished_at' => now(), 'wake_at' => null])->save();
        Activity::record($run->workflow->workspace_id, 'workflow.cancel', $run->workflow->name, null, $user, ['run' => $run->id]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return StepResult
     */
    private function execute(WorkflowRun $run, array $node): array
    {
        $rules = (array) ($node['rules'] ?? []);
        $workflow = $run->workflow;

        // What a step does is the workflow's doing, as whoever saved it.
        return AuditContext::within(['via' => 'workflow', 'workflow' => $workflow->name, 'run' => $run->id], fn (): array => match ($node['kind']) {
            'trigger' => ['state' => 'succeeded', 'message' => $run->trigger['summary']],
            'redeploy' => $this->redeploy($run, $node, $rules),
            'release' => $this->release($run, $node, $rules),
            'restart', 'start', 'stop' => $this->serviceAction($run, $node, $rules, $node['kind']),
            'command' => $this->command($run, $node, $rules),
            'agent' => $this->agent($run, $node, $rules),
            'backup' => $this->backup($run, $node, $rules),
            'copy_data' => $this->copyData($run, $node, $rules),
            'promote' => $this->promote($run, $node, $rules),
            'check_monitor' => $this->checkMonitor($run, $rules, now()->getTimestamp() + 60 * max(1, min(30, (int) ($rules['timeout'] ?? 5)))),
            'http' => $this->http($run, $rules),
            'alert' => $this->alert($run, $rules),
            'wait' => ['state' => 'waiting', 'until' => now()->getTimestamp() + 60 * max(1, min(1440, (int) ($rules['minutes'] ?? 1))), 'message' => 'Waiting '.max(1, (int) ($rules['minutes'] ?? 1)).' min'],
            'branch' => $this->branch($run, $rules),
            default => ['state' => 'failed', 'message' => "{$workflow->name} has a step this version doesn’t know."],
        });
    }

    /**
     * A waiting step whose time came.
     *
     * @param  array<string, mixed>  $node
     */
    private function resume(WorkflowRun $run, array $node): void
    {
        $step = $run->steps[$node['id']];
        if ($node['kind'] === 'check_monitor') {
            $this->settle($run, $node, $this->checkMonitor($run, (array) $node['rules'], (int) ($step['deadline'] ?? 0)));

            return;
        }
        $this->settle($run, $node, ['state' => 'succeeded', 'message' => 'Waited '.(int) ((array) $node['rules'])['minutes'].' min']);
    }

    /**
     * Record how a step ended (or that it's still going) and queue what
     * follows it.
     *
     * @param  array<string, mixed>  $node
     * @param  StepResult  $result
     */
    private function settle(WorkflowRun $run, array $node, array $result): void
    {
        $steps = $run->steps;
        $id = $node['id'];
        $steps[$id] = [...($steps[$id] ?? []), ...$result];
        $state = $result['state'];
        $label = $node['title'] !== '' ? $node['title'] : WorkflowText::kindLabel($node['kind']);

        if (in_array($state, ['succeeded', 'failed'], true)) {
            $steps[$id]['finishedAt'] = now()->getTimestamp();
            $port = $result['port'] ?? ($state === 'succeeded' ? 'out' : 'failed');
            $steps[$id]['port'] = $port;
            $this->log($run, $id, $state === 'succeeded' ? 'success' : 'error', $label.': '.($result['message'] ?? ($state === 'succeeded' ? 'done' : 'failed')));

            $next = collect($run->graph['edges'] ?? [])->where('source', $id)->where('port', $port)->pluck('target')->filter(fn ($target) => is_string($target))->values()->all();
            if ($state === 'failed' && $next === []) {
                $steps[$id]['unhandled'] = true;
            }
            $traversed = [];
            foreach ($next as $target) {
                $steps[$target] ??= ['state' => 'pending'];
                $traversed[] = collect($run->graph['edges'])->first(fn ($e) => $e['source'] === $id && $e['port'] === $port && $e['target'] === $target)['id'] ?? null;
            }
            $steps[$id]['edges'] = array_values(array_filter($traversed));
            $run->frontier = [...$run->frontier, ...$next];
        } elseif ($state === 'waiting' && isset($result['message']) && ! isset($run->steps[$id]['until'])) {
            $this->log($run, $id, 'info', "{$label}: {$result['message']}");
        } elseif ($state === 'running' && isset($result['message'])) {
            $this->log($run, $id, 'info', "{$label}: {$result['message']}");
        }

        $run->steps = $steps;
    }

    private function finishIfDone(WorkflowRun $run): void
    {
        $busy = collect($run->steps)->contains(fn ($s) => in_array($s['state'] ?? null, ['running', 'waiting', 'pending'], true));
        $waits = collect($run->steps)->where('state', 'waiting')->pluck('until')->filter();
        $run->wake_at = $waits->isEmpty() ? null : now()->setTimestamp((int) $waits->min());

        if ($busy || $run->frontier !== []) {
            return;
        }

        $failed = collect($run->steps)->contains(fn ($s) => ($s['unhandled'] ?? false) === true);
        $run->state = $failed ? 'failed' : 'succeeded';
        $run->finished_at = now();
        $seconds = (int) $run->started_at->diffInSeconds(now());
        $this->log($run, null, $failed ? 'error' : 'success', $failed
            ? 'Run failed after '.MonitorAlerts::duration($seconds).'.'
            : 'Run finished in '.MonitorAlerts::duration($seconds).'.');

        if ($failed) {
            $workflow = $run->workflow;
            Activity::system($workflow->workspace_id, 'workflow.failed', $workflow->name, null, ['run' => $run->id]);

            // Nobody was watching an automatic run: say it failed.
            if (($run->trigger['kind'] ?? 'manual') !== 'manual') {
                $step = collect($run->steps)->first(fn ($s) => ($s['unhandled'] ?? false) === true);
                $this->alerts->notify($workflow->workspace, 'workflow.failed', "{$workflow->name} failed", array_values(array_filter([
                    $step['message'] ?? null,
                    'Started by: '.$run->trigger['summary'],
                ])), 'https://'.$workflow->workspace->host()."/workflows/{$workflow->id}?run={$run->id}", true, 'Open the run');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function redeploy(WorkflowRun $run, array $node, array $rules): array
    {
        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        $service = $rules['service'] === WorkflowSteps::FROM_TRIGGER ? ($run->trigger['data']['service'] ?? null) : $rules['service'];
        $last = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $service)
            ->whereNotNull('command')
            ->orderByRaw("case when state = 'succeeded' then 0 else 1 end")
            ->latest()
            ->first();
        if ($last === null) {
            return ['state' => 'failed', 'message' => "There’s no earlier deploy of {$service} on {$machine->name} to repeat."];
        }

        // Started by a push to the app's own repository: deploy what was
        // pushed, with the rest of its last deploy (domain, settings, port).
        $pushed = self::pushedCommit($last->command['job'] ?? [], (array) ($run->trigger['data'] ?? []));
        if ($pushed !== null) {
            $job = [...$last->command['job'], 'commit' => $pushed];

            return $this->sent($run, $node, fn () => $this->queue->deploy($machine, $user, $job), 'Deploying '.substr($pushed, 0, 7)." of {$service} on {$machine->name}");
        }

        return $this->sent($run, $node, fn () => $this->queue->retry($last, $user), "Redeploying {$service} on {$machine->name}");
    }

    /**
     * The commit a push trigger carried, when it was pushed to the GitHub
     * repository the app is built from and isn't what it already runs.
     *
     * @param  array<string, mixed>  $spec  the app's last deploy
     * @param  array<string, mixed>  $data  what the trigger carried
     */
    public static function pushedCommit(array $spec, array $data): ?string
    {
        $commit = strtolower((string) ($data['commit'] ?? ''));
        if (! preg_match('/^[0-9a-f]{40}$/', $commit) || $commit === strtolower((string) ($spec['commit'] ?? ''))
            || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+/[A-Za-z0-9._-]+?)(?:\.git)?/?$#i', (string) ($spec['repo'] ?? ''), $m)) {
            return null;
        }

        return strcasecmp($m[1], (string) ($data['repo'] ?? '')) === 0 ? $commit : null;
    }

    /**
     * Release an app that runs on the machine at the pushed commit, or its
     * branch's latest.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function release(WorkflowRun $run, array $node, array $rules): array
    {
        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        [$key, $service, $error] = $this->service($run, $machine, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        // Its steps run commands on the machine, like the console.
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return ['state' => 'failed', 'message' => "{$user->name} needs two-factor authentication on for workflows to release apps as them."];
        }

        $workspace = $run->workflow->workspace;
        try {
            $commit = ReleaseSpec::commit($workspace, $rules, (array) ($run->trigger['data'] ?? []));
            $job = ReleaseSpec::job($workspace, $rules, $commit);
        } catch (RuntimeException $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }

        return $this->sent($run, $node, fn () => $this->queue->queue($machine, $user, 'release', $job, $key, self::JOB_TIMEOUT_MINUTES * 60 - 60),
            'Releasing '.substr($commit, 0, 7)." to {$job['path']} on {$machine->name}");
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function serviceAction(WorkflowRun $run, array $node, array $rules, string $action): array
    {
        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        [$key, $service, $error] = $this->service($run, $machine, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }

        return $this->sent($run, $node, fn () => $this->queue->queue($machine, $user, 'service_action', [
            'type' => 'service_action', 'service' => $key, 'action' => $action,
        ], $key), ucfirst(match ($action) {
            'restart' => 'restarting', 'start' => 'starting', default => 'stopping'
        })." {$service['name']} on {$machine->name}");
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function command(WorkflowRun $run, array $node, array $rules): array
    {
        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        [$key, $service, $error] = $this->service($run, $machine, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        if (! str_starts_with($key, 'docker:')) {
            return ['state' => 'failed', 'message' => 'Commands run inside containers; '.$service['name'].' isn’t one.'];
        }
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return ['state' => 'failed', 'message' => "{$user->name} needs two-factor authentication on for workflows to run commands as them."];
        }

        $text = WorkflowText::render((string) $rules['command'], $run);

        return $this->sent($run, $node, fn () => $this->queue->queue($machine, $user, 'service_exec', [
            'type' => 'service_exec', 'service' => $key, 'command' => $text, 'timeout_secs' => 300,
        ], $key), "Running “{$text}” in {$service['name']}");
    }

    /**
     * Run a coding agent in a container on the machine, and wait for it.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function agent(WorkflowRun $run, array $node, array $rules): array
    {
        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        [$key, $service, $error] = $this->service($run, $machine, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        if (! str_starts_with($key, 'docker:')) {
            return ['state' => 'failed', 'message' => 'Agents run inside containers; '.$service['name'].' isn’t one.'];
        }
        // It runs commands on the machine, like the console.
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return ['state' => 'failed', 'message' => "{$user->name} needs two-factor authentication on for workflows to run agents as them."];
        }

        $minutes = AgentSpec::minutes($rules['minutes'] ?? null);
        try {
            $job = AgentSpec::job($run->workflow->workspace, $run, $node['id'], $key, $rules);
        } catch (RuntimeException $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }
        $agent = AgentSpec::AGENTS[(string) (($rules['agent'] ?? '') ?: 'claude')];

        $result = $this->sent($run, $node, fn () => $this->queue->queue($machine, $user, 'service_exec', $job, $key, $minutes * 60 + 60),
            "{$agent} is working in {$service['name']} on {$machine->name}, for up to {$minutes} min");
        $result['timeout'] = $minutes + 5;

        return $result;
    }

    /**
     * How an agent's run ended, from what its script printed: the summary
     * goes in the run's log, and the step keeps it (and the pull request)
     * for later steps' {{agent.summary}} and {{agent.pr}}.
     *
     * @param  array<string, mixed>  $node
     * @return StepResult
     */
    private function agentFinished(WorkflowRun $run, array $node, ?DaemonJob $job, bool $failed): array
    {
        if ($job === null) {
            return ['state' => 'failed', 'message' => 'The agent’s job is gone.'];
        }
        $outcome = AgentSpec::outcome($job);
        foreach (array_slice(preg_split('/\R/', $outcome['summary']) ?: [], 0, 40) as $line) {
            if (trim($line) !== '') {
                $this->log($run, $node['id'], 'info', mb_substr($line, 0, 300));
            }
        }
        $kept = ['summary' => $outcome['summary'], 'pr' => $outcome['pr'], 'model' => $outcome['model']];
        $agent = AgentSpec::AGENTS[(string) ((((array) $node['rules'])['agent'] ?? '') ?: 'claude')] ?? 'The agent';
        $by = $outcome['model'] !== null ? " ({$agent}, {$outcome['model']})" : " ({$agent})";

        if ($failed) {
            return [...$kept, 'state' => 'failed', 'message' => $outcome['error'] ?? ($job->state === 'succeeded'
                ? 'The agent stopped with exit code '.(int) ($job->result['exit_code'] ?? 1).'. Its log on the machine’s page says why.'
                : $this->jobError($job))];
        }

        return [...$kept, 'state' => 'succeeded', 'message' => match (true) {
            $outcome['pr'] !== null => "Opened {$outcome['pr']}{$by}",
            $outcome['note'] !== null => $outcome['note'].$by,
            default => "The agent finished{$by}",
        }];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function backup(WorkflowRun $run, array $node, array $rules): array
    {
        if (($rules['machine_id'] ?? null) === WorkflowSteps::ALL_MACHINES) {
            return $this->backupEverything($run, $node);
        }

        [$machine, $user, $error] = $this->machineAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }

        $since = now()->subSecond();
        try {
            $started = $this->backups->run($machine, $user, 'workflow');
        } catch (RuntimeException $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }
        $jobs = $this->claimBackups($run, $node, [$machine->id], $since);

        return $jobs === []
            ? ['state' => 'failed', 'message' => 'No backups could be started.']
            : ['state' => 'running', 'jobs' => $jobs, 'message' => "Backing up {$started} ".($started === 1 ? 'service' : 'services')." on {$machine->name}"];
    }

    /**
     * Back up every machine that can be: one that's offline or has nothing
     * to back up is noted and skipped, and the step fails only if none
     * could start (or, once they finish, if any failed).
     *
     * @param  array<string, mixed>  $node
     * @return StepResult
     */
    private function backupEverything(WorkflowRun $run, array $node): array
    {
        $workflow = $run->workflow;
        $user = $workflow->runAs;
        if ($user === null || ! $workflow->workspace->allows($user, WorkspacePermission::ManageMachines)) {
            return ['state' => 'failed', 'message' => 'Whoever last saved this workflow can no longer manage machines. Save it again as someone who can.'];
        }

        $since = now()->subSecond();
        [$machines, $services] = [[], 0];
        foreach ($workflow->workspace->machines()->orderBy('name')->get() as $machine) {
            if (($reason = $this->queue->unavailable($machine)) !== null) {
                $this->log($run, $node['id'], 'warning', "Skipped {$machine->name}: {$reason}");

                continue;
            }
            try {
                $services += $this->backups->run($machine, $user, 'workflow');
                $machines[] = $machine->id;
            } catch (RuntimeException $e) {
                $this->log($run, $node['id'], 'warning', "Skipped {$machine->name}: {$e->getMessage()}");
            }
        }

        $jobs = $this->claimBackups($run, $node, $machines, $since);
        $count = count($machines);

        return $jobs === []
            ? ['state' => 'failed', 'message' => 'No machine could be backed up.']
            : ['state' => 'running', 'jobs' => $jobs, 'message' => "Backing up {$services} ".($services === 1 ? 'service' : 'services')." on {$count} ".($count === 1 ? 'machine' : 'machines')];
    }

    /**
     * The backup jobs just started on these machines, tied to this step so
     * it waits for them.
     *
     * @param  array<string, mixed>  $node
     * @param  list<int>  $machineIds
     * @return list<string>
     */
    private function claimBackups(WorkflowRun $run, array $node, array $machineIds, mixed $since): array
    {
        $jobs = array_values(Backup::query()->whereIn('machine_id', $machineIds)->where('created_at', '>=', $since)->whereNotNull('daemon_job_id')
            ->get(['daemon_job_id'])->map(fn (Backup $backup) => $backup->daemon_job_id)->filter()->all());
        DaemonJob::query()->whereIn('id', $jobs)->update(['workflow_run_id' => $run->id, 'workflow_node' => $node['id']]);

        return $jobs;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function checkMonitor(WorkflowRun $run, array $rules, int $deadline): array
    {
        $monitor = Monitor::query()->where('workspace_id', $run->workflow->workspace_id)->find((int) ($rules['monitor_id'] ?? 0));
        if ($monitor === null) {
            return ['state' => 'failed', 'message' => 'The monitor is gone.'];
        }
        if ($monitor->paused) {
            return ['state' => 'failed', 'message' => "{$monitor->name} is paused."];
        }

        $this->monitors->run(collect([$monitor->load('workspace')]));
        $monitor->refresh();
        $passed = $monitor->failures === 0 && $monitor->last_error === null && $monitor->last_checked_at?->gt(now()->subMinute());

        if ($passed) {
            return ['state' => 'succeeded', 'message' => "{$monitor->name} is up".($monitor->last_response_ms ? " ({$monitor->last_response_ms} ms)" : '')];
        }
        if (now()->getTimestamp() >= $deadline) {
            return ['state' => 'failed', 'message' => "{$monitor->name} still isn’t up: ".($monitor->last_error ?? 'check failed')];
        }

        return ['state' => 'waiting', 'until' => now()->getTimestamp() + 30, 'deadline' => $deadline, 'message' => "Checking {$monitor->name} until it’s up"];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function http(WorkflowRun $run, array $rules): array
    {
        $url = WorkflowText::render((string) ($rules['url'] ?? ''), $run, encode: true);
        $method = in_array($rules['method'] ?? 'POST', ['GET', 'POST', 'PUT'], true) ? $rules['method'] : 'POST';
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! is_string($host) || ! in_array($scheme, ['http', 'https'], true)) {
            return ['state' => 'failed', 'message' => 'Not a valid address.'];
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return ['state' => 'failed', 'message' => 'Private and internal addresses can’t be called from the panel.'];
        }
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        $body = trim((string) ($rules['body'] ?? ''));
        $payload = $body === '' ? [
            'workflow' => $run->workflow->name,
            'run' => $run->id,
            'trigger' => $run->trigger['summary'],
        ] : WorkflowText::render($body, $run);

        $start = hrtime(true);
        try {
            $request = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'ServerOS-Workflows/1.0'])
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
            if (is_string($payload)) {
                $request = $request->withBody($payload, json_validate($payload) ? 'application/json' : 'text/plain');
            }
            $response = match ($method) {
                'GET' => $request->get($url),
                'PUT' => is_array($payload) ? $request->put($url, $payload) : $request->put($url),
                default => is_array($payload) ? $request->post($url, $payload) : $request->post($url),
            };
        } catch (Throwable) {
            return ['state' => 'failed', 'message' => "{$method} {$host} didn’t answer."];
        }
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        return $response->successful()
            ? ['state' => 'succeeded', 'message' => "{$method} {$host} answered {$response->status()} in {$ms} ms"]
            : ['state' => 'failed', 'message' => "{$method} {$host} answered {$response->status()}"];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function alert(WorkflowRun $run, array $rules): array
    {
        $workflow = $run->workflow;
        $workspace = $workflow->workspace;
        $subject = WorkflowText::render((string) ($rules['subject'] ?? $workflow->name), $run);
        $message = WorkflowText::render((string) ($rules['message'] ?? ''), $run);
        $bad = ($rules['tone'] ?? 'bad') === 'bad';
        $to = in_array($rules['to'] ?? 'team', WorkflowSteps::ALERT_TO, true) ? ($rules['to'] ?? 'team') : 'team';
        $emails = $to === 'team' ? [] : (WorkflowSteps::emails($rules['emails'] ?? null) ?? []);

        if ($to !== 'emails') {
            $this->alerts->notify($workspace, 'workflow.alert', $subject, array_values(array_filter([$message, "From the workflow “{$workflow->name}”."])),
                'https://'.$workspace->host()."/workflows/{$workflow->id}?run={$run->id}", $bad, 'Open the run',
                ['workflow' => ['id' => $workflow->id, 'name' => $workflow->name, 'run' => $run->id]]);
        }
        if ($emails !== []) {
            try {
                Notification::route('mail', $emails)->notify(new WorkflowMessage($subject, $message, $workspace->name, $workspace->owner?->email, $bad, app(Extensions::class)->poweredByUrl($workspace)));
            } catch (Throwable $e) {
                report($e);

                return ['state' => 'failed', 'message' => 'Couldn’t email '.implode(', ', $emails).'.'];
            }
        }

        $who = match (true) {
            $to === 'team' => 'the team',
            $to === 'emails' => implode(', ', $emails),
            default => 'the team and '.implode(', ', $emails),
        };

        return ['state' => 'succeeded', 'message' => "Sent “{$subject}” to {$who}"];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function branch(WorkflowRun $run, array $rules): array
    {
        $workspaceId = $run->workflow->workspace_id;
        [$answer, $why] = match ($rules['condition'] ?? null) {
            'monitor_up' => (function () use ($rules, $workspaceId) {
                $monitor = Monitor::query()->where('workspace_id', $workspaceId)->find((int) ($rules['monitor_id'] ?? 0));

                return [$monitor?->status === 'up', $monitor ? "{$monitor->name} is {$monitor->status}" : 'The monitor is gone'];
            })(),
            'service_running' => (function () use ($run, $rules) {
                $machine = $this->machine($run, $rules);
                if ($machine === null) {
                    return [false, 'The machine is gone'];
                }
                [$key, $service] = $this->service($run, $machine, $rules);
                $running = $service !== null && ($service['status'] ?? null) === 'running';

                return [$running, ($service['name'] ?? 'The service').($running ? ' is running' : ' isn’t running')];
            })(),
            'disk_below' => (function () use ($run, $rules) {
                $machine = $this->machine($run, $rules);
                $limit = (int) ($rules['percent'] ?? 85);

                return [$machine !== null && $machine->disk_percent !== null && $machine->disk_percent < $limit,
                    $machine ? "{$machine->name}’s disk is ".($machine->disk_percent ?? '?').'% full' : 'The machine is gone'];
            })(),
            'time_between' => (function () use ($rules) {
                $now = now()->format('H:i');
                $from = (string) ($rules['from'] ?? '09:00');
                $to = (string) ($rules['to'] ?? '17:00');
                $inside = $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);

                return [$inside, "It’s {$now} UTC"];
            })(),
            'trigger_field' => (function () use ($run, $rules) {
                $field = (string) ($rules['field'] ?? '');
                $actual = data_get($run->trigger['data'] ?? [], $field);
                $expected = (string) ($rules['value'] ?? '');
                $actual = is_scalar($actual) ? (string) $actual : json_encode($actual);

                return [$actual === $expected, "{$field} is “".mb_substr((string) $actual, 0, 60).'”'];
            })(),
            default => [false, 'Nothing to check'],
        };

        return ['state' => 'succeeded', 'port' => $answer ? 'true' : 'false', 'message' => $why.' → '.($answer ? 'yes' : 'no')];
    }

    /** How long a copy may take, past the hour the machine allows it. */
    public const COPY_TIMEOUT_MINUTES = 70;

    /** Several apps may deploy one after another on one machine. */
    public const PROMOTE_TIMEOUT_MINUTES = 90;

    /**
     * Copy the data into an environment's databases again from the one it
     * was copied from, and wait for every copy that started.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function copyData(WorkflowRun $run, array $node, array $rules): array
    {
        [$project, $user, $error] = $this->projectAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        $environment = $project->environments()->whereKey((int) ($rules['environment'] ?? 0))->first();
        if ($environment === null) {
            return ['state' => 'failed', 'message' => "That environment is no longer in {$project->name}."];
        }
        if ($environment->source_id === null) {
            return ['state' => 'failed', 'message' => "{$environment->name} wasn’t copied from another environment, so there’s nothing to copy its data from."];
        }

        $this->data->refresh($environment, $user);
        // Every database in it: the ones just started, and any copy already
        // running (a nightly refresh, Copy data now), which this waits on too.
        $databases = $environment->nodes()->whereNotNull('origin_id')->whereIn('template', ProjectData::COPYABLE)->get();
        $copying = $databases->filter(fn (ProjectNode $n) => $n->data_state === 'copying' && $n->data_job_id !== null)->values();
        $failed = $databases->firstWhere('data_state', 'failed');
        if ($databases->isEmpty()) {
            return ['state' => 'failed', 'message' => 'There’s no database in it to copy into.'];
        }
        if ($failed !== null) {
            return ['state' => 'failed', 'message' => "{$failed->name}: ".($failed->data_error ?? 'its copy didn’t start.')];
        }
        if ($copying->isEmpty()) {
            $first = $databases->first();

            return ['state' => 'failed', 'message' => "{$first->name}: ".($first->data_error ?? 'its copy didn’t start.')];
        }

        $jobs = array_values($copying->map(fn (ProjectNode $n) => (string) $n->data_job_id)->all());
        DaemonJob::query()->whereIn('id', $jobs)->update(['workflow_run_id' => $run->id, 'workflow_node' => $node['id']]);
        $waiting = $databases->where('data_state', 'waiting')->count();
        $source = $environment->source->name;

        return [
            'state' => 'running',
            'jobs' => $jobs,
            'timeout' => self::COPY_TIMEOUT_MINUTES,
            'message' => 'Copying '.count($copying).' '.(count($copying) === 1 ? 'database' : 'databases')." from {$source} into {$environment->name}"
                .($waiting > 0 ? " ({$waiting} more waiting, copied when ready)" : ''),
        ];
    }

    /**
     * Deploy each app in one environment at the code its copy in another
     * runs, and wait for every deploy.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    private function promote(WorkflowRun $run, array $node, array $rules): array
    {
        [$project, $user, $error] = $this->projectAndUser($run, $rules);
        if ($error !== null) {
            return ['state' => 'failed', 'message' => $error];
        }
        $from = $project->environments()->whereKey((int) ($rules['from'] ?? 0))->first();
        $to = $project->environments()->whereKey((int) ($rules['to'] ?? 0))->first();
        if (! $from instanceof ProjectEnvironment || ! $to instanceof ProjectEnvironment || $from->id === $to->id) {
            return ['state' => 'failed', 'message' => "Those environments are no longer in {$project->name}."];
        }

        if (! Environments::related($from, $to)) {
            return ['state' => 'failed', 'message' => "{$from->name} wasn’t copied from {$to->name}, or the other way round, so their apps don’t pair up."];
        }

        ['deployed' => $deploys, 'blocked' => $blocked] = $this->environments->promote($from, $to, $user);
        if ($blocked !== []) {
            return ['state' => 'failed', 'message' => 'Nothing was promoted. '.collect($blocked)->map(fn (string $why, string $app) => "{$app}: {$why}")->implode(' ')];
        }
        if ($deploys === []) {
            return ['state' => 'succeeded', 'message' => 'Nothing to promote: every app is already at that code.'];
        }

        $jobs = array_values(array_map(fn (DaemonJob $job) => (string) $job->id, $deploys));
        DaemonJob::query()->whereIn('id', $jobs)->update(['workflow_run_id' => $run->id, 'workflow_node' => $node['id']]);
        Activity::record($project->workspace, 'project.promote', "{$from->name} to {$to->name} in {$project->name}", meta: ['apps' => array_keys($deploys)]);

        return ['state' => 'running', 'jobs' => $jobs, 'timeout' => self::PROMOTE_TIMEOUT_MINUTES, 'message' => "Deploying {$from->name}’s code to ".implode(', ', array_keys($deploys))." in {$to->name}"];
    }

    /**
     * The project a step names, in the workflow's workspace, and who it
     * acts as.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: Project, 1: User, 2: null}|array{0: null, 1: null, 2: string}
     */
    private function projectAndUser(WorkflowRun $run, array $rules): array
    {
        $workflow = $run->workflow;
        $project = Project::query()->where('workspace_id', $workflow->workspace_id)->find((int) ($rules['project'] ?? 0));
        if ($project === null) {
            return [null, null, 'The project is gone; choose another.'];
        }
        $user = $workflow->runAs;
        if ($user === null || ! $workflow->workspace->allows($user, WorkspacePermission::ManageMachines)) {
            return [null, null, 'Whoever last saved this workflow can no longer manage machines. Save it again as someone who can.'];
        }

        return [$project, $user, null];
    }

    /**
     * Queue a server job for a step and wait for it.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(): DaemonJob  $send
     * @return StepResult
     */
    private function sent(WorkflowRun $run, array $node, callable $send, string $doing): array
    {
        try {
            $job = $send();
        } catch (RuntimeException $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }
        $job->forceFill(['workflow_run_id' => $run->id, 'workflow_node' => $node['id']])->save();

        return ['state' => 'running', 'jobs' => [$job->id], 'message' => $doing];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: Machine|null, 1: User|null, 2: string|null}
     */
    private function machineAndUser(WorkflowRun $run, array $rules): array
    {
        $machine = $this->machine($run, $rules);
        if ($machine === null) {
            return [null, null, 'The machine is gone, or this trigger isn’t about one.'];
        }
        $workflow = $run->workflow;
        $user = $workflow->runAs;
        if ($user === null || ! $workflow->workspace->allows($user, WorkspacePermission::ManageMachines)) {
            return [$machine, null, 'Whoever last saved this workflow can no longer manage machines. Save it again as someone who can.'];
        }
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            return [$machine, $user, $reason];
        }

        return [$machine, $user, null];
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function machine(WorkflowRun $run, array $rules): ?Machine
    {
        $id = ($rules['machine_id'] ?? null) === WorkflowSteps::FROM_TRIGGER ? ($run->trigger['data']['machine_id'] ?? null) : ($rules['machine_id'] ?? null);

        return Machine::query()->where('workspace_id', $run->workflow->workspace_id)->find((int) $id);
    }

    /**
     * The service a step acts on, from its settings or the trigger.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: string|null, 1: array<string, mixed>|null, 2: string|null}
     */
    private function service(WorkflowRun $run, Machine $machine, array $rules): array
    {
        $key = ($rules['service'] ?? null) === WorkflowSteps::FROM_TRIGGER ? ($run->trigger['data']['service_key'] ?? null) : ($rules['service'] ?? null);
        $services = $machine->inventory['services'] ?? [];
        $service = collect(is_array($services) ? $services : [])->first(fn ($s) => is_array($s) && ($s['key'] ?? null) === $key);
        if ($service === null) {
            return [$key, null, "That service isn’t on {$machine->name} any more."];
        }
        if (($service['details']['managed'] ?? null) !== 'true') {
            return [$key, $service, "{$service['name']} isn’t managed by ServerOS yet; adopt it first."];
        }

        return [$key, $service, null];
    }

    private function jobError(DaemonJob $job): string
    {
        $message = is_array($job->error) ? (string) ($job->error['message'] ?? '') : '';

        return match ($job->state) {
            'timed_out' => 'It timed out on the machine.',
            'cancelled' => 'It was cancelled on the machine.',
            'refused' => $message ?: 'The machine refused it.',
            default => $message ?: 'It failed on the machine.',
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function jobSuccess(array $node, ?DaemonJob $job): string
    {
        if ($node['kind'] === 'command' && $job !== null) {
            $out = trim((string) ($job->result['stdout'] ?? $job->result['output'] ?? ''));

            return $out === '' ? 'Command finished' : 'Command finished: '.mb_substr(str_replace("\n", ' ⏎ ', $out), 0, 200);
        }

        return match ($node['kind']) {
            'redeploy' => 'Deployed',
            'release' => $job !== null && is_string($job->result['commit'] ?? null) ? 'Released '.substr($job->result['commit'], 0, 7) : 'Released',
            'restart' => 'Restarted',
            'start' => 'Started',
            'stop' => 'Stopped',
            'backup' => 'Backed up',
            'copy_data' => 'Data copied',
            'promote' => 'Promoted',
            default => 'Done',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function node(WorkflowRun $run, string $id): ?array
    {
        foreach ($run->graph['nodes'] ?? [] as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
        }

        return null;
    }

    private function shift(WorkflowRun $run): ?string
    {
        $frontier = $run->frontier;
        $id = array_shift($frontier);
        $run->frontier = $frontier;

        return $id;
    }

    private function log(WorkflowRun $run, ?string $node, string $level, string $message): void
    {
        $logs = $run->logs ?? [];
        $logs[] = ['t' => (int) floor(microtime(true) * 1000), 'node' => $node, 'level' => $level, 'message' => $message];
        $run->logs = array_slice($logs, -self::LOG_LIMIT);
    }
}
