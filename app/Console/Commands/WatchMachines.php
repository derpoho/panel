<?php

namespace App\Console\Commands;

use App\Support\Health\MachineWatcher;
use Illuminate\Console\Command;

class WatchMachines extends Command
{
    protected $signature = 'machines:watch';

    protected $description = 'Alert when a server stops reporting or its disk nearly fills';

    public function handle(MachineWatcher $watcher): int
    {
        $this->info("Sent {$watcher->run()} alerts.");

        return self::SUCCESS;
    }
}
