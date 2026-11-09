<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Support\Backups\BackupRunner;
use Illuminate\Console\Command;
use RuntimeException;

class RunDueBackups extends Command
{
    protected $signature = 'backups:run-due';

    protected $description = 'Back up machines whose daily or weekly backup is due';

    /** How long after the last scheduled run a machine is due again. */
    private const array INTERVALS = ['daily' => 23, 'weekly' => 6 * 24 + 23];

    public function handle(BackupRunner $runner): int
    {
        $machines = Machine::query()
            ->whereIn('backup_schedule', array_keys(self::INTERVALS))
            ->whereNotNull('backup_destination_id')
            ->with(['workspace.owner', 'backupDestination'])
            ->get();

        foreach ($machines as $machine) {
            $last = $machine->backups()->where('trigger', 'scheduled')->latest('id')->value('created_at');
            if ($last !== null && $last->gt(now()->subHours(self::INTERVALS[$machine->backup_schedule]))) {
                continue;
            }

            $owner = $machine->workspace->owner;
            if ($owner === null || $runner->unavailable($machine) !== null) {
                // Offline or not set up: try again next hour.
                continue;
            }

            try {
                $started = $runner->run($machine, $owner, 'scheduled');
                $this->line("{$machine->name}: {$started} service(s)");
            } catch (RuntimeException $e) {
                $this->warn("{$machine->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
