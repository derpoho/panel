<?php

namespace App\Support\Machines;

use App\Models\Activity;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\DaemonUpdates;
use App\Support\Monitors\MonitorAlerts;

/**
 * Telling a workspace about daemon updates: when a release is
 * waiting for someone to approve it on machines whose policy asks first,
 * and when a machine's daemon has updated. Both go in Activity; the alert
 * (email, webhook) is the "updates" kind, which a workspace can switch off.
 * Machines that update on their own only get the Activity line.
 */
final class DaemonUpdateNotices
{
    public function __construct(private readonly MonitorAlerts $alerts) {}

    /**
     * A new release is waiting on these machines (all in one workspace).
     *
     * @param  list<Machine>  $machines
     */
    public function waiting(Workspace $workspace, DaemonRelease $release, array $machines): void
    {
        if ($machines === []) {
            return;
        }
        $count = count($machines);
        $names = collect($machines)->pluck('name')->take(5)->implode(', ').($count > 5 ? ' '.__('and :count more', ['count' => $count - 5]) : '');
        Activity::system($workspace->id, 'daemon.update_waiting', $release->version, meta: ['machines' => $count]);

        $lines = [
            $count === 1
                ? "{$names} waits for you to approve it: its policy asks first."
                : "{$count} machines wait for you to approve it, because their policy asks first: {$names}.",
            'Open a machine’s settings to see what’s in it and install it, or install it on every machine a policy covers from Settings › Machine policies.',
        ];
        if ($release->notes_url !== null) {
            $lines[] = "What’s in it: {$release->notes_url}";
        }
        $this->alerts->notify($workspace, 'daemon.update_waiting', "Daemon {$release->version} is ready to install", $lines,
            'https://'.$workspace->host().'/settings/machine-policies', false, 'Review the update',
            ['release' => ['version' => $release->version, 'notes' => $release->notes_url], 'machines' => $count]);
    }

    /**
     * The machine reconnected on a newer daemon than before.
     */
    public function updated(Machine $machine, string $from, string $to): void
    {
        if (! DaemonUpdates::newer($to, $from)) {
            return;
        }
        Activity::system($machine->workspace_id, 'machine.daemon_updated', $machine->name, $machine, ['from' => $from, 'to' => $to]);

        // Updates that install on their own happen often; Activity is enough.
        if (MachinePolicies::for($machine)->updates === 'automatic') {
            return;
        }
        $this->alerts->notify($machine->workspace, 'daemon.updated', "{$machine->name} is on daemon {$to}", [
            "It updated from {$from} and has reconnected.",
        ], 'https://'.$machine->workspace->host().'/machines/'.$machine->id.'/settings', false, 'Open the machine',
            ['machine' => ['id' => $machine->id, 'name' => $machine->name], 'from' => $from, 'to' => $to]);
    }
}
