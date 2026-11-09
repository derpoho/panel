<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Firewall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Opening and closing a machine's ports to the internet. The daemon reads
 * the firewall, makes the change, reads it again to check, and sends back
 * what it found, which replaces the firewall in the machine's inventory.
 */
class MachineFirewallController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'action' => ['required', Rule::in(['open', 'close'])],
            'port' => ['required', 'integer', 'between:1,65535'],
            'proto' => ['sometimes', Rule::in(['tcp', 'udp'])],
        ]);
        $port = (int) $data['port'];
        $proto = $data['proto'] ?? 'tcp';

        $refused = $data['action'] === 'close'
            ? Firewall::refuseClose($machine, $port, $proto)
            : (Firewall::report($machine) === null ? Firewall::unavailable(null) : null);
        if ($refused !== null) {
            return response()->json(['error' => $refused], 409);
        }
        if (self::changing($machine)) {
            return response()->json(['error' => 'The firewall is already being changed on this machine. Try again in a moment.'], 409);
        }
        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'firewall_rule', self::job($data['action'], $port, $proto), null, 120);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        Activity::record($workspace, 'machine.firewall', $machine->name, $machine, meta: ['action' => $data['action'], 'port' => "{$port}/{$proto}"]);

        return response()->json(['jobId' => $job->id], 202);
    }

    /** Where a change is, for the page to follow. */
    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'firewall_rule', 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'phase' => $job->phase,
            'error' => $job->error,
            'exposure' => $job->result['exposure'] ?? null,
        ]);
    }

    /**
     * The daemon job for opening or closing a port.
     *
     * @return array{type: string, action: string, rule: string}
     */
    public static function job(string $action, int $port, string $proto): array
    {
        return ['type' => 'firewall_rule', 'action' => $action, 'rule' => "{$port}/{$proto}"];
    }

    /** A change already queued or running on the machine. */
    public static function changing(Machine $machine): bool
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'firewall_rule')
            ->whereIn('state', ['queued', 'running'])
            ->get()
            ->contains(fn (DaemonJob $job) => ! $job->isStale());
    }
}
