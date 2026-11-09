<?php

namespace App\Http\Controllers;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use App\Support\Machines\DiskUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * "See what's using it" on the machine page: the daemon scans the disk,
 * read-only, and the page shows the biggest folders and what's safe to
 * clear. Nothing is deleted from here.
 */
class MachineDiskUsageController extends Controller
{
    #[NotAudited('A read-only scan of the disk')]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (($reason = DiskUsage::unavailable($machine)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'disk_usage', ['type' => 'disk_usage'], null, DiskUsage::TIMEOUT);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(DiskUsage::present($job), 202);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'disk_usage', 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(DiskUsage::present($job));
    }
}
