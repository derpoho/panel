<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Workspace;

/**
 * serveros.com: what the workspace's plan (or trial) allows, with the deals
 * set from /admin on top. A workspace with no plan and no trial is inactive
 * and gets the "no plan" values below.
 */
class PlanEntitlements implements Entitlements
{
    public function active(Workspace $workspace): bool
    {
        return $workspace->effectivePlan() !== null;
    }

    public function has(Workspace $workspace, Feature $feature): bool
    {
        $plan = $workspace->effectivePlan();

        if ($plan === null) {
            return false;
        }

        return match ($feature) {
            Feature::StaffRoles => $plan->hasStaffRoles(),
            Feature::CustomRoles => $plan->hasCustomRoles(),
            Feature::Sso => $plan->hasSso(),
            Feature::Scim => $plan->hasScim(),
            Feature::CustomDomains => $plan->hasCustomDomains(),
            Feature::OwnLogStorage => $plan->hasOwnLogStorage(),
            Feature::FleetStatusPage => $plan->hasFleetStatusPage(),
            Feature::StatusPages, Feature::Automations => in_array($plan, [Plan::Pro, Plan::Business], true),
        };
    }

    public function limit(Workspace $workspace, Limit $limit): ?int
    {
        $plan = $workspace->effectivePlan();

        return match ($limit) {
            Limit::Machines => $this->machines($workspace, $plan),
            Limit::McpCalls => match (true) {
                $workspace->mcp_call_limit_override !== null && $workspace->mcp_call_limit_override < 0 => null,
                $workspace->mcp_call_limit_override !== null => $workspace->mcp_call_limit_override,
                default => $plan?->mcpCallLimit() ?? 0,
            },
            // A day of logs without a plan, and enough lines to try them out.
            Limit::LogRetentionDays => $plan?->logRetentionDays() ?? 1,
            Limit::LogLinesPerDay => $plan?->logLinesPerDay() ?? 50_000,
            // Only a paid plan lengthens it: a trial ending never shortens it.
            Limit::ActivityRetentionDays => $workspace->plan?->activityRetentionDays() ?? Activity::KEEP_DAYS,
            Limit::Monitors => $plan?->monitorLimit() ?? 0,
            Limit::MinMonitorInterval => $plan?->minMonitorInterval() ?? 300,
            Limit::StatusSubscribers => $plan?->statusSubscriberLimit() ?? 0,
            Limit::ApiRequestsPerMinute => $plan === null ? 60 : (int) (config('api.rate_limits')[$plan->value] ?? 60),
        };
    }

    /** The plan's machines, unless a deal set from /admin replaces them. */
    private function machines(Workspace $workspace, ?Plan $plan): ?int
    {
        if ($plan === null) {
            return 0;
        }

        return match (true) {
            $workspace->machine_limit_override === null => $plan->machineLimit(),
            $workspace->machine_limit_override < 0 => null,
            default => $workspace->machine_limit_override,
        };
    }
}
