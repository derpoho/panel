<?php

namespace App\Console\Commands;

use App\Support\Updates\PanelUpdates;
use Illuminate\Console\Command;

class ClaimPanelUpdate extends Command
{
    protected $signature = 'panel:update-claim';

    protected $description = 'Hand the server the update someone asked for in the panel, if there is one';

    public function handle(): int
    {
        $update = PanelUpdates::claim();
        if ($update !== null) {
            $this->line("{$update->id} {$update->version}");
        }

        return self::SUCCESS;
    }
}
