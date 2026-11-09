<?php

namespace App\Support\Machines;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Holding packages at the version they're on (apt-mark hold, dnf
 * versionlock) so updates leave them alone, and letting them go again.
 * The daemon answers with every package held, which replaces the
 * machine's list (PackageUpdates::ingest).
 */
final class PackageHolds
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * Send the hold (or unhold) to each machine that can take it.
     *
     * @param  Collection<int, Machine>  $machines
     * @param  list<string>  $packages
     * @return array{queued: list<array{machineId: int, machine: string, jobId: string}>, skipped: list<array{machineId: int, machine: string, reason: string}>}
     */
    public function apply(Workspace $workspace, User $by, Collection $machines, array $packages, bool $hold): array
    {
        $queued = [];
        $skipped = [];
        foreach ($machines as $machine) {
            $reason = match (true) {
                ($why = $this->queue->unavailable($machine)) !== null => $why,
                ! DaemonJobQueue::canInstallVersions($machine) => 'Its daemon is too old to hold packages. Update it first with “sudo serverosd update”.',
                default => null,
            };
            if ($reason === null) {
                try {
                    $job = $this->queue->queue($machine, $by, 'package_hold', ['type' => 'package_hold', 'packages' => $packages, 'hold' => $hold], null, 120);
                    $queued[] = ['machineId' => $machine->id, 'machine' => $machine->name, 'jobId' => $job->id];

                    continue;
                } catch (RuntimeException $e) {
                    $reason = $e->getMessage();
                }
            }
            $skipped[] = ['machineId' => $machine->id, 'machine' => $machine->name, 'reason' => $reason];
        }

        if ($queued !== []) {
            Activity::record($workspace, $hold ? 'updates.hold' : 'updates.unhold', implode(', ', array_slice($packages, 0, 5)).(count($packages) > 5 ? ' and '.(count($packages) - 5).' more' : ''), null, $by, [
                'packages' => count($packages),
                'machines' => count($queued),
            ]);
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }
}
