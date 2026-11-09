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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Updating a machine's operating system from the panel: check what's
 * waiting, install security updates or everything, and restart it when an
 * update needs that. The daemon runs the package manager (apt, dnf or yum)
 * and streams its output back as the job's log.
 */
class MachineUpdatesController extends Controller
{
    /** Installing can take a while on a server that's far behind. */
    private const INSTALL_TIMEOUT = 1800;

    /** How long alerts are held while a machine restarts. */
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
            // Just these packages. Names as the package manager lists them,
            // never starting with a dash so they can't pass for options.
            'packages' => ['sometimes', 'array', 'min:1', 'max:500'],
            'packages.*' => ['string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9+._:~-]*$/'],
        ]);

        if ($this->busy($machine)) {
            return response()->json(['error' => 'Updates are already being installed on this machine.'], 409);
        }
        if (isset($data['packages']) && ! self::canPick($machine)) {
            return response()->json(['error' => 'This machine’s daemon can only install every update. Update it with “sudo serverosd update” to pick which ones.'], 409);
        }

        // A daemon that doesn't know package_install refuses it, rather
        // than upgrading everything.
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

    /**
     * Restart the machine. Alerts and workflows about it are held for a few
     * minutes (maintenance), so the reboot itself doesn't page anyone.
     */
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

    /**
     * What the machine's last check found, kept: the dialog paints it at
     * once while a fresh check runs.
     */
    public function snapshot(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        return response()->json([
            ...PackageUpdates::snapshot($machine),
            'canPick' => self::canPick($machine),
        ]);
    }

    /** Where an update job is, for the dialog to follow. */
    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id, 404);
        abort_unless(in_array($job->kind, [...PackageUpdates::KINDS, 'reboot'], true), 404);

        $result = $job->result ?? [];

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'phase' => $job->phase,
            'log' => array_slice($job->log ?? [], -300),
            'error' => $job->error,
            'canPick' => self::canPick($machine),
            'report' => in_array($job->kind, ['package_updates', ...PackageUpdates::INSTALL_KINDS], true) && $result !== [] ? self::report($result) : null,
        ]);
    }

    /**
     * A check's or install's result as the dialog shows it: every package
     * a newer daemon lists, else the lines an older one sends.
     *
     * @param  array<string, mixed>  $result
     * @return array{manager: mixed, packages: list<array{name: string, from: string|null, to: string, security: bool}>, rebootRequired: bool, applied: bool, held: list<string>, complete: bool}
     */
    public static function report(array $result): array
    {
        [$rows, $complete] = PackageUpdates::rows($result);

        return [
            'manager' => $result['manager'] ?? null,
            'packages' => array_map(fn (array $row) => ['name' => $row['name'], 'from' => $row['installed'], 'to' => $row['candidate'], 'security' => $row['security']], $rows),
            'rebootRequired' => (bool) ($result['reboot_required'] ?? false),
            'applied' => (bool) ($result['applied'] ?? false),
            'held' => array_values(array_filter(is_array($result['held'] ?? null) ? $result['held'] : [], 'is_string')),
            'complete' => $complete,
        ];
    }

    /**
     * One line of the package manager's list as a name, versions, and
     * whether it's a security update.
     *
     * apt: "openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]"
     * dnf: "openssl.x86_64  1:3.0.7-28.el9  baseos"
     *
     * @param  list<string>  $security  the lines reported as security updates
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

    /** Whether the machine's daemon can install just some packages. */
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
