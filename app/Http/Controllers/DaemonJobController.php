<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class DaemonJobController extends Controller
{
    /**
     * Send a deploy again exactly as it was sent, as a new job. A job that's
     * still queued or running can only be retried once it has gone quiet.
     */
    #[Audited]
    public function retry(Request $request, Workspace $workspace, Machine $machine, DaemonJob $job, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeJob($workspace, $machine, $job);

        if (! $job->isRetryable()) {
            return response()->json(['error' => 'This deploy was sent before ServerOS kept deploy settings, so it can’t be retried. Deploy it again from the library.'], 422);
        }
        if ($job->isActive() && ! $job->isStale()) {
            return response()->json(['error' => 'This deploy is still running.'], 409);
        }
        if (($reason = $queue->unavailable($machine, $job->kind)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        if ($job->isActive()) {
            $this->stopWaiting($job);
        }

        try {
            $retry = $queue->retry($job, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $retry->id, 'state' => 'queued'], 202);
    }

    /**
     * Stop waiting on a job the daemon never reported back on. This only
     * changes what the panel shows; it doesn't reach the machine.
     */
    #[Audited]
    public function abandon(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        $this->authorizeJob($workspace, $machine, $job);

        if (! $job->isActive()) {
            return response()->json(['error' => 'This job has already finished.'], 409);
        }

        $this->stopWaiting($job);
        Activity::record($workspace, 'job.abandon', $job->service ?? $machine->name, $machine, meta: ['kind' => $job->kind]);

        return response()->json(['jobId' => $job->id, 'state' => $job->state]);
    }

    private function authorizeJob(Workspace $workspace, Machine $machine, DaemonJob $job): void
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }

    private function stopWaiting(DaemonJob $job): void
    {
        $job->forceFill([
            'state' => 'cancelled',
            'error' => ['phase' => 'panel', 'message' => 'Stopped waiting: the daemon never reported back on this job.'],
            'finished_at' => now(),
        ])->save();
    }
}
