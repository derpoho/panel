<?php

namespace App\Console\Commands;

use App\Support\Maintenance\ScheduledMaintenance;
use Illuminate\Console\Command;

class MaintenanceTick extends Command
{
    protected $signature = 'maintenance:tick';

    protected $description = 'Start planned maintenance on time, move its work along, and finish it';

    public function handle(ScheduledMaintenance $maintenance): int
    {
        $changed = $maintenance->tick();
        if ($changed > 0) {
            $this->info("{$changed} maintenance window(s) moved on.");
        }

        return self::SUCCESS;
    }
}
