<?php

namespace App\Support\Admin;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * A plan set by ServerOS staff rather than paid for: a comp, a deal, the
 * startup programme, or taking a manual plan away. Recorded in the admin
 * history and on the workspace's own activity. Doesn't touch the subscription.
 */
class ManualPlan
{
    public static function set(
        User $admin,
        Workspace $workspace,
        ?Plan $plan,
        ?BillingInterval $interval,
        ?CarbonInterface $until,
        ?int $machineLimit,
        string $note,
    ): void {
        $before = self::snapshot($workspace);

        $workspace->forceFill($plan === null ? [
            'plan' => null,
            'billing_interval' => null,
            'plan_source' => null,
            'manual_plan_until' => null,
            'machine_limit_override' => null,
            'plan_note' => $note,
        ] : [
            'plan' => $plan,
            'billing_interval' => $interval,
            'plan_source' => 'manual',
            'manual_plan_until' => $until?->copy()->endOfDay(),
            'trial_ends_at' => null,
            'machine_limit_override' => $machineLimit,
            'plan_note' => $note,
        ])->save();

        AdminAction::record($admin, 'workspace.plan_set', $workspace, ['before' => $before, 'after' => self::snapshot($workspace), 'note' => $note]);
        Activity::system($workspace, 'plan.set_by_serveros', $plan?->label(), null, array_filter([
            'interval' => $plan ? $workspace->billing_interval?->value : null,
            'until' => $workspace->manual_plan_until?->format('j M Y'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Workspace $workspace): array
    {
        return [
            'plan' => $workspace->plan?->label(),
            'interval' => $workspace->billing_interval?->value,
            'until' => $workspace->manual_plan_until?->toDateString(),
            'machines' => $workspace->machine_limit_override,
        ];
    }
}
