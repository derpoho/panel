<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use App\Support\Machines\PackageUpdates;
use App\Support\Machines\StaleServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class MachineUpdatesController extends Controller
{
    private const INSTALL_TIMEOUT = 1800;

    private const REBOOT_QUIET_MINUTES = 10;

    #[NotAudited('A check for updates; installing them is recorded')]
    public function check(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorize($workspace, $machine);

        return $this->queue($queue, $machine, $request, 'package_updates', ['type' => 'package_updates', 'apply' => false, 'security_only' => false], 300);
    }

    #[Audited]
    public function install(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorize($workspace, $machine);
        $data = $request->validate([
            'security_only' => ['required', 'boolean'],
            'packages' => ['sometimes', 'array', 'min:1', 'max:500'],
            'packages.*' => ['string', 'max:200', 'not_regex:/-$/', 'regex:'.PackageUpdates::NAME_PATTERN],
        ], ['packages.*.not_regex' => 'A package name can’t end with a dash.']);

        if ($this->busy($machine)) {
            return response()->json(['error' => 'Updates are already being installed on this machine.'], 409);
        }
        if (isset($data['packages']) && ! self::canPick($machine)) {
            return response()->json(['error' => 'This machine’s daemon can only install every update. Update it with “sudo serverosd update” to pick which ones.'], 409);
        }

        $packages = isset($data['packages']) ? array_values(array_unique($data['packages'])) : null;
        [$kind, $job] = $packages !== null
            ? ['package_install', ['type' => 'package_install', 'packages' => $packages]]
            : ['package_updates', ['type' => 'package_updates', 'apply' => true, 'security_only' => $data['security_only']]];

        $response = $this->queue($queue, $machine, $request, $kind, $job, self::INSTALL_TIMEOUT);
        if ($response->status() === 202) {
            Activity::record($workspace, 'machine.update', $machine->name, $machine, meta: [
                'security_only' => $data['security_only'],
                'packages' => $packages !== null ? count($packages) : null,
            ]);
        }

        return $response;
    }

    #[Audited]
    public function reboot(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorize($workspace, $machine);

        $response = $this->queue($queue, $machine, $request, 'reboot', ['type' => 'reboot'], 60);
        if ($response->status() === 202) {
            if (! $machine->inMaintenance()) {
                $machine->forceFill([
                    'maintenance_until' => now()->addMinutes(self::REBOOT_QUIET_MINUTES),
                    'maintenance_reason' => 'Restarting after updates',
                    'maintenance_public' => false,
                ])->save();
            }
            Activity::record($workspace, 'machine.reboot', $machine->name, $machine);
        }

        return $response;
    }

    /** Restart the services still running files an update replaced, so it applies without a reboot. */
    #[Audited]
    public function restart(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorize($workspace, $machine);
        $data = $request->validate([
            'include' => ['sometimes', 'array', 'max:20'],
            'include.*' => ['string', 'regex:'.StaleServices::UNIT_PATTERN],
        ]);

        if ($this->busy($machine)) {
            return response()->json(['error' => 'Updates are being installed on this machine. Restart once they’re done.'], 409);
        }
        $job = StaleServices::job($machine, $data['include'] ?? []);
        if ($job === null) {
            return response()->json(['error' => 'Nothing on this machine is waiting for a restart.'], 409);
        }

        $response = $this->queue($queue, $machine, $request, StaleServices::KIND, $job, StaleServices::TIMEOUT);
        if ($response->status() === 202) {
            Activity::record($workspace, 'machine.restart_services', $machine->name, $machine, meta: [
                'services' => array_slice(array_map(fn (string $u) => preg_replace('/\.service$/', '', $u), $job['units']), 0, 20),
            ]);
        }

        return $response;
    }

    public function snapshot(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        return response()->json([
            ...PackageUpdates::snapshot($machine),
            'canPick' => self::canPick($machine),
        ]);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id, 404);
        abort_unless(in_array($job->kind, [...PackageUpdates::KINDS, StaleServices::KIND, 'reboot'], true), 404);

        $result = $job->result ?? [];

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'phase' => $job->phase,
            'log' => array_slice($job->log ?? [], -300),
            'error' => $job->error,
            'canPick' => self::canPick($machine),
            'report' => in_array($job->kind, ['package_updates', ...PackageUpdates::INSTALL_KINDS], true) && $result !== [] ? self::report($result) : null,
            'restarts' => $job->kind === StaleServices::KIND && $result !== [] ? [
                'failed' => array_values(array_map(
                    fn (array $r) => ['unit' => (string) $r['unit'], 'message' => is_string($r['message'] ?? null) ? mb_substr($r['message'], 0, 300) : null],
                    array_filter(is_array($result['restarted'] ?? null) ? $result['restarted'] : [], fn ($r) => is_array($r) && is_string($r['unit'] ?? null) && ! ($r['ok'] ?? false)),
                )),
                'after' => StaleServices::fromResult($result),
            ] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{manager: mixed, packages: list<array{name: string, from: string|null, to: string, security: bool}>, rebootRequired: bool, restarts: array<string, mixed>|null, applied: bool, held: list<string>, complete: bool}
     */
    public static function report(array $result): array
    {
        [$rows, $complete] = PackageUpdates::rows($result);

        return [
            'manager' => $result['manager'] ?? null,
            'packages' => array_map(fn (array $row) => ['name' => $row['name'], 'from' => $row['installed'], 'to' => $row['candidate'], 'security' => $row['security']], $rows),
            'rebootRequired' => ($restarts = StaleServices::fromResult($result)) !== null ? $restarts['reboot'] !== [] : (bool) ($result['reboot_required'] ?? false),
            'restarts' => $restarts,
            'applied' => (bool) ($result['applied'] ?? false),
            'held' => array_values(array_filter(is_array($result['held'] ?? null) ? $result['held'] : [], 'is_string')),
            'complete' => $complete,
        ];
    }

    /**
     * @param  list<string>  $security
     * @return array{name: string, from: string|null, to: string|null, security: bool}
     */
    public static function package(string $line, array $security): array
    {
        $isSecurity = in_array($line, $security, true);

        if (preg_match('#^([^/\s]+)/\S+\s+(\S+)\s+\S+(?:\s+\[upgradable from:\s*([^\]]+)\])?#', $line, $m)) {
            return ['name' => $m[1], 'from' => isset($m[3]) ? trim($m[3]) : null, 'to' => $m[2], 'security' => $isSecurity];
        }

        $parts = preg_split('/\s+/', trim($line)) ?: [];
        $name = preg_replace('/\.(x86_64|aarch64|noarch|i686)$/', '', $parts[0] ?? $line);
        $isSecurity = $isSecurity || collect($security)->contains(fn ($s) => str_contains((string) $s, (string) $name));

        return ['name' => (string) $name, 'from' => null, 'to' => $parts[1] ?? null, 'security' => $isSecurity];
    }

    public static function canPick(Machine $machine): bool
    {
        $version = ltrim((string) $machine->daemon_version, 'v');

        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1
            && DaemonRelease::compare($version, (string) config('daemon.package_install_since')) >= 0;
    }

    private function authorize(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }

    private function busy(Machine $machine): bool
    {
        return PackageUpdates::installing($machine) !== null;
    }

    /** @param  array<string, mixed>  $job */
    private function queue(DaemonJobQueue $queue, Machine $machine, Request $request, string $kind, array $job, int $timeout): JsonResponse
    {
        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), $kind, $job, null, $timeout);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id], 202);
    }
}
