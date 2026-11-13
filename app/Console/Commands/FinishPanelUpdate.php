<?php

namespace App\Console\Commands;

use App\Models\PanelUpdate;
use App\Support\Updates\PanelUpdates;
use Illuminate\Console\Command;

class FinishPanelUpdate extends Command
{
    protected $signature = 'panel:update-finished {update} {--failed= : Why the update failed}';

    protected $description = 'Record how an update from the panel went, then update the daemon on its workspace’s machines';

    public function handle(PanelUpdates $updates): int
    {
        $update = PanelUpdate::query()->whereKey((int) $this->argument('update'))->where('status', PanelUpdate::RUNNING)->first();
        if ($update === null) {
            $this->error('No update with that id is running.');

            return self::FAILURE;
        }

        $failed = $this->option('failed');
        if (is_string($failed) && $failed !== '') {
            PanelUpdates::failed($update, $failed);
            $this->line("{$update->version}: failed");

            return self::SUCCESS;
        }

        $machines = $updates->finish($update);
        $update->refresh();
        $this->line($update->status === PanelUpdate::DONE ? "{$update->version}: done, {$machines} machines sent the newest daemon" : "{$update->version}: {$update->error}");

        return self::SUCCESS;
    }
}
