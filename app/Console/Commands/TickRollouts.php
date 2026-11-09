<?php

namespace App\Console\Commands;

use App\Support\Machines\PackageRollouts;
use Illuminate\Console\Command;

class TickRollouts extends Command
{
    protected $signature = 'updates:tick';

    protected $description = 'Start OS update rollouts on time and move them along, batch by batch';

    public function handle(PackageRollouts $rollouts): int
    {
        $count = $rollouts->tick();
        if ($count > 0) {
            $this->info("{$count} rollout(s) looked at.");
        }

        return self::SUCCESS;
    }
}
