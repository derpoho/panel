<?php

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use App\Support\Monitors\MonitorRunner;
use Illuminate\Console\Command;

class RunMonitors extends Command
{
    protected $signature = 'monitors:run {--prune : Also delete checks older than a week}';

    protected $description = 'Check every uptime monitor that is due';

    public function handle(MonitorRunner $runner): int
    {
        $checked = $runner->runDue();
        $this->info("Checked {$checked} monitors.");

        if ($this->option('prune')) {
            $deleted = MonitorCheck::query()->where('checked_at', '<', now()->subDays(MonitorCheck::KEEP_DAYS))->delete();
            $this->info("Deleted {$deleted} old checks.");
        }

        return self::SUCCESS;
    }
}
