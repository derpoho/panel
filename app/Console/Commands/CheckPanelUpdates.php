<?php

namespace App\Console\Commands;

use App\Support\Updates\PanelUpdates;
use Illuminate\Console\Command;

class CheckPanelUpdates extends Command
{
    protected $signature = 'panel:check-updates';

    protected $description = 'Check for a newer ServerOS release';

    public function handle(): int
    {
        $check = PanelUpdates::check();
        if (! $check['reached']) {
            $this->warn('Couldn’t reach '.config('serveros.updates.feed_url').'.');

            return self::FAILURE;
        }
        $available = PanelUpdates::available();
        $this->line($available === null ? 'ServerOS '.PanelUpdates::current().' is the newest.' : "ServerOS {$available['version']} is out.");

        return self::SUCCESS;
    }
}
