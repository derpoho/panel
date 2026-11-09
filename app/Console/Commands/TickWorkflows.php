<?php

namespace App\Console\Commands;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSchedule;
use Illuminate\Console\Command;

class TickWorkflows extends Command
{
    protected $signature = 'workflows:tick {--prune : Also delete runs older than 30 days}';

    protected $description = 'Start scheduled workflows and move running ones on';

    public function handle(WorkflowEngine $engine): int
    {
        $started = 0;
        Workflow::query()
            ->with('workspace')
            ->where('enabled', true)
            ->where('trigger', 'schedule')
            ->where('next_run_at', '<=', now())
            ->limit(200)
            ->get()
            ->each(function (Workflow $workflow) use ($engine, &$started) {
                // Move the next time on first, so a slow run can't start twice.
                $workflow->forceFill(['next_run_at' => WorkflowSchedule::next($workflow->triggerRules())])->save();
                if ($engine->start($workflow, 'schedule', 'On schedule') !== null) {
                    $started++;
                }
            });

        $moved = $engine->tick();
        $this->info("Started {$started}, moved {$moved}.");

        if ($this->option('prune')) {
            $deleted = WorkflowRun::query()->where('started_at', '<', now()->subDays(WorkflowRun::KEEP_DAYS))->delete();
            $this->info("Deleted {$deleted} old runs.");
        }

        return self::SUCCESS;
    }
}
