<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Workspace;
use Illuminate\Console\Command;

class ExpireManualPlans extends Command
{
    protected $signature = 'plans:expire-manual';

    protected $description = 'End plans set by hand from /admin once their end date has passed';

    public function handle(): int
    {
        $ended = 0;

        Workspace::query()
            ->where('plan_source', 'manual')
            ->whereNotNull('plan')
            ->whereNotNull('manual_plan_until')
            ->where('manual_plan_until', '<=', now())
            ->each(function (Workspace $workspace) use (&$ended) {
                $plan = $workspace->plan?->label();

                $workspace->forceFill([
                    'plan' => null,
                    'billing_interval' => null,
                    'plan_source' => null,
                    'manual_plan_until' => null,
                    'machine_limit_override' => null,
                ])->save();

                Activity::system($workspace, 'plan.manual_ended', "the {$plan} plan");
                $this->line("{$workspace->name}: {$plan} ended");
                $ended++;
            });

        $this->info("Ended {$ended} manual plan(s).");

        return self::SUCCESS;
    }
}
