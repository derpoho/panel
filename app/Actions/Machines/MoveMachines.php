<?php

namespace App\Actions\Machines;

use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Machines\MachinePolicies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves machines into another workspace with what belongs to them: their
 * services, status page, service groups, backups and history go with the
 * machine row, and this carries the rest across. The daemon keeps running;
 * it's the same machine, now answering to the new workspace.
 *
 * Monitors on the machine move. A backup destination moves too when nothing
 * left behind uses it, or is copied when something does, and backups made
 * to it point at whichever the machine now uses. Machine groups stay
 * behind, and the old workspace's status page stops listing the machine.
 * Workflows in the old workspace that name it are left as they are.
 */
class MoveMachines
{
    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<string> Notes for anything that didn't come across as is.
     */
    public function move(Collection $machines, Workspace $to): array
    {
        $notes = [];

        DB::transaction(function () use ($machines, $to, &$notes) {
            $movingIds = array_values($machines->map(fn (Machine $machine) => $machine->id)->all());
            /** @var array<int, int> $copies old destination id => its copy in $to */
            $copies = [];

            foreach ($machines as $machine) {
                $from = $machine->workspace;

                if ($machine->backup_destination_id !== null) {
                    $notes = [...$notes, ...$this->bringDestination($machine, $to, $movingIds, $copies)];
                }

                Monitor::query()->where('machine_id', $machine->id)->update(['workspace_id' => $to->id]);

                // Its group and policy were the old workspace's: it follows the
                // new one's default, sent once the move is done.
                $machine->forceFill(['workspace_id' => $to->id, 'machine_group_id' => null, 'machine_policy_id' => null])->save();

                $this->dropFromFleetPage($from, $machine->id);

                Activity::record($from, 'machine.move_out', $machine->name, null, null, ['to' => $to->name]);
                Activity::record($to, 'machine.move_in', $machine->name, $machine, null, ['from' => $from->name]);
            }
        });
        MachinePolicies::push($machines);

        return $notes;
    }

    /**
     * @param  list<int>  $movingIds
     * @param  array<int, int>  $copies
     * @return list<string>
     */
    private function bringDestination(Machine $machine, Workspace $to, array $movingIds, array &$copies): array
    {
        $destination = BackupDestination::query()->find($machine->backup_destination_id);
        if ($destination === null || $destination->workspace_id === $to->id) {
            return [];
        }

        // Backups to another machine that's staying behind can't follow.
        if ($destination->machine_id !== null && ! in_array($destination->machine_id, $movingIds, true)) {
            $machine->forceFill(['backup_destination_id' => null])->save();

            return ["{$machine->name}: its backups went to a machine that stayed behind, so they're off until you pick a destination."];
        }

        $sharedWithStayers = Machine::query()
            ->where('backup_destination_id', $destination->id)
            ->whereNotIn('id', $movingIds)
            ->exists();

        if (! $sharedWithStayers) {
            $destination->forceFill(['workspace_id' => $to->id])->save();

            return [];
        }

        if (! isset($copies[$destination->id])) {
            $copy = $destination->replicate();
            $copy->forceFill(['workspace_id' => $to->id])->save();
            $copies[$destination->id] = $copy->id;
        }
        $copyId = $copies[$destination->id];

        $machine->forceFill(['backup_destination_id' => $copyId])->save();
        // So its backup history restores from the destination it can see.
        $machine->backups()->where('backup_destination_id', $destination->id)->update(['backup_destination_id' => $copyId]);

        return [];
    }

    private function dropFromFleetPage(Workspace $from, int $machineId): void
    {
        $page = WorkspaceStatusPage::query()->where('workspace_id', $from->id)->first();
        if ($page === null || ! is_array($page->machines)) {
            return;
        }

        $page->forceFill(['machines' => array_values(array_diff($page->machines, [$machineId]))])->save();
    }
}
