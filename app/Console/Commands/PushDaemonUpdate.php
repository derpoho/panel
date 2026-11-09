<?php

namespace App\Console\Commands;

use App\Support\DaemonUpdates;
use Illuminate\Console\Command;

class PushDaemonUpdate extends Command
{
    protected $signature = 'daemon:push-update {--arch= : Only machines on amd64 or arm64}';

    protected $description = 'Offer the newest stable daemon release to every connected machine that runs an older one, now';

    public function handle(DaemonUpdates $updates): int
    {
        $result = $updates->push($this->option('arch') ?: null);

        $this->components->info(count($result['offered']).' machines offered the update'.($result['offered'] === [] ? '.' : ': '.implode(', ', $result['offered'])));
        foreach ($result['failed'] as $machine => $reason) {
            $this->components->warn("{$machine}: {$reason}");
        }

        return self::SUCCESS;
    }
}
