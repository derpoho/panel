<?php

namespace App\Support\Fixes;

use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\DaemonJob;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs an approved fix: its steps one after another as ordinary jobs, as
 * the person who approved it, stopping at the first that fails. Each step
 * is checked against the machine again first, in case it changed since the
 * agent looked.
 */
class FixRunner
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * @throws RuntimeException when it can't start
     */
    public function approve(AgentFix $fix, User $user): void
    {
        if ($fix->state !== 'needs_approval') {
            throw new RuntimeException('This fix isn’t waiting for approval any more.');
        }
        if (($reason = $this->queue->unavailable($fix->machine)) !== null) {
            throw new RuntimeException($reason);
        }

        $fix->forceFill(['state' => 'running', 'decided_by' => $user->id, 'decided_at' => now(), 'results' => []])->save();
        Activity::record($fix->workspace_id, 'fix.approve', $fix->title ?? 'A fix', $fix->machine, $user, ['fix' => $fix->id, 'steps' => count($fix->actions ?? [])]);
        $this->next($fix, $user);
    }

    public function decline(AgentFix $fix, User $user): void
    {
        if ($fix->state !== 'needs_approval') {
            throw new RuntimeException('This fix isn’t waiting for approval any more.');
        }
        $fix->forceFill(['state' => 'declined', 'decided_by' => $user->id, 'decided_at' => now(), 'finished_at' => now()])->save();
        Activity::record($fix->workspace_id, 'fix.decline', $fix->title ?? 'A fix', $fix->machine, $user, ['fix' => $fix->id]);
    }

    /** A step's job finished: note how, then run the next or finish. */
    public function jobFinished(DaemonJob $job): void
    {
        DB::transaction(function () use ($job) {
            $fix = AgentFix::query()->lockForUpdate()->find($job->agent_fix_id);
            if ($fix === null || $fix->state !== 'running') {
                return;
            }
            $results = $fix->results ?? [];
            $i = collect($results)->search(fn ($r) => ($r['job'] ?? null) === $job->id);
            if ($i === false || ($results[$i]['state'] ?? null) !== 'running') {
                return;
            }

            $exit = $job->result['exit_code'] ?? null;
            $ok = $job->state === 'succeeded' && ($exit === null || (int) $exit === 0);
            $results[$i]['state'] = $ok ? 'succeeded' : 'failed';
            $results[$i]['message'] = $ok ? 'Done' : ($exit !== null && $job->state === 'succeeded'
                ? 'The command stopped with exit code '.(int) $exit.'.'
                : (is_array($job->error) && filled($job->error['message'] ?? null) ? mb_substr((string) $job->error['message'], 0, 300) : 'It failed on the machine.'));
            $results[$i]['log'] = array_map(fn ($l) => mb_substr((string) $l, 0, 300), array_slice(array_values(array_filter($job->log ?? [], 'is_string')), -10));
            $fix->results = $results;

            if (! $ok) {
                $fix->forceFill(['state' => 'failed', 'finished_at' => now()])->save();
                Activity::system($fix->workspace_id, 'fix.failed', $fix->title ?? 'A fix', $fix->machine, ['fix' => $fix->id]);

                return;
            }
            $fix->save();
            $this->next($fix, $fix->decider);
        });
    }

    private function next(AgentFix $fix, ?User $user): void
    {
        $results = $fix->results ?? [];
        $i = count($results);
        $steps = $fix->actions ?? [];
        if ($i >= count($steps)) {
            $fix->forceFill(['state' => 'fixed', 'finished_at' => now()])->save();
            Activity::system($fix->workspace_id, 'fix.done', $fix->title ?? 'A fix', $fix->machine, ['fix' => $fix->id]);

            return;
        }

        // Still allowed? The machine may have changed since the agent looked.
        $step = FixActions::clean([$steps[$i]], $fix->machine)[0] ?? null;
        if ($user === null || $step === null) {
            $results[] = ['state' => 'failed', 'message' => $user === null ? 'Whoever approved it can’t act on the machine any more.' : 'That service isn’t on the machine, or isn’t managed by ServerOS, any more.'];
            $fix->forceFill(['results' => $results, 'state' => 'failed', 'finished_at' => now()])->save();

            return;
        }

        [$kind, $job] = FixActions::job($step);
        try {
            $record = $this->queue->queue($fix->machine, $user, $kind, $job, $step['service']);
        } catch (RuntimeException $e) {
            $results[] = ['state' => 'failed', 'message' => $e->getMessage()];
            $fix->forceFill(['results' => $results, 'state' => 'failed', 'finished_at' => now()])->save();

            return;
        }
        $record->forceFill(['agent_fix_id' => $fix->id])->save();
        $results[] = ['state' => 'running', 'job' => $record->id];
        $fix->forceFill(['results' => $results])->save();
    }
}
