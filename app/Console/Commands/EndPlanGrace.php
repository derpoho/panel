<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Workspace;
use Illuminate\Console\Command;

class EndPlanGrace extends Command
{
    protected $signature = 'plans:end-grace';

    protected $description = 'End plans whose renewal payment still hasn’t gone through once their grace period is over';

    public function handle(): int
    {
        $ended = 0;

        Workspace::query()
            ->whereNotNull('plan')
            ->whereNotNull('plan_grace_until')
            ->where('plan_grace_until', '<=', now())
            ->where(fn ($q) => $q->whereNull('plan_source')->orWhere('plan_source', '!=', 'manual'))
            ->each(function (Workspace $workspace) use (&$ended) {
                $plan = $workspace->plan?->label();

                $workspace->forceFill([
                    'plan' => null,
                    'billing_interval' => null,
                    'plan_grace_until' => null,
                ])->save();

                Activity::system($workspace, 'plan.ended', "the {$plan} plan", meta: ['reason' => 'unpaid']);
                $this->line("{$workspace->name}: {$plan} ended");
                $ended++;
            });

        $this->info("Ended {$ended} unpaid plan(s).");

        return self::SUCCESS;
    }
}
