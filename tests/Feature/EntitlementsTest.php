<?php

use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SelfHostedEntitlements;

/** Switch the install to an edition, as AppServiceProvider would at boot. */
function useEdition(string $edition): void
{
    config(['serveros.edition' => $edition]);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => $edition === 'self-hosted' ? new SelfHostedEntitlements : new PlanEntitlements);
}

test('serveros.com is the default edition, where the plan decides', function () {
    expect(app(Entitlements::class))->toBeInstanceOf(PlanEntitlements::class);

    $business = Workspace::factory()->onPlan(Plan::Business)->create();
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();

    expect($business->isActive())->toBeTrue()
        ->and($business->hasFeature(Feature::Sso))->toBeTrue()
        ->and($business->hasFeature(Feature::CustomRoles))->toBeTrue()
        ->and($business->limitOf(Limit::Machines))->toBeNull()
        ->and($essential->hasFeature(Feature::StaffRoles))->toBeFalse()
        ->and($essential->hasFeature(Feature::Automations))->toBeFalse()
        ->and($essential->limitOf(Limit::Machines))->toBe(1)
        ->and($essential->limitOf(Limit::ApiRequestsPerMinute))->toBe((int) config('api.rate_limits.starter'));
});

test('on serveros.com every feature and limit matches the plan', function (Plan $plan) {
    $workspace = Workspace::factory()->onPlan($plan)->create();

    expect($workspace->hasFeature(Feature::StaffRoles))->toBe($plan->hasStaffRoles())
        ->and($workspace->hasFeature(Feature::CustomRoles))->toBe($plan->hasCustomRoles())
        ->and($workspace->hasFeature(Feature::Sso))->toBe($plan->hasSso())
        ->and($workspace->hasFeature(Feature::Scim))->toBe($plan->hasScim())
        ->and($workspace->hasFeature(Feature::CustomDomains))->toBe($plan->hasCustomDomains())
        ->and($workspace->hasFeature(Feature::OwnLogStorage))->toBe($plan->hasOwnLogStorage())
        ->and($workspace->hasFeature(Feature::FleetStatusPage))->toBe($plan->hasFleetStatusPage())
        ->and($workspace->hasFeature(Feature::StatusPages))->toBe($plan !== Plan::Essential)
        ->and($workspace->hasFeature(Feature::Automations))->toBe($plan !== Plan::Essential)
        ->and($workspace->machineLimit())->toBe($plan->machineLimit())
        ->and($workspace->mcpCallLimit())->toBe($plan->mcpCallLimit())
        ->and($workspace->logRetentionDays())->toBe($plan->logRetentionDays())
        ->and($workspace->logLinesPerDay())->toBe($plan->logLinesPerDay())
        ->and($workspace->activityRetentionDays())->toBe($plan->activityRetentionDays())
        ->and($workspace->monitorLimit())->toBe($plan->monitorLimit())
        ->and($workspace->minMonitorInterval())->toBe($plan->minMonitorInterval())
        ->and($workspace->limitOf(Limit::StatusSubscribers))->toBe($plan->statusSubscriberLimit());
})->with([Plan::Essential, Plan::Pro, Plan::Business]);

test('on serveros.com a workspace with no plan or trial is inactive and gets the no-plan values', function () {
    $lapsed = Workspace::factory()->create(['plan' => null, 'trial_ends_at' => now()->subDay()]);

    expect($lapsed->isActive())->toBeFalse()
        ->and($lapsed->hasFeature(Feature::StaffRoles))->toBeFalse()
        ->and($lapsed->machineLimit())->toBe(0)
        ->and($lapsed->mcpCallLimit())->toBe(0)
        ->and($lapsed->logRetentionDays())->toBe(1)
        ->and($lapsed->logLinesPerDay())->toBe(50_000)
        ->and($lapsed->activityRetentionDays())->toBe(Activity::KEEP_DAYS)
        ->and($lapsed->monitorLimit())->toBe(0)
        ->and($lapsed->minMonitorInterval())->toBe(300);
});

test('on serveros.com deals set from admin still replace the plan', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['machine_limit_override' => -1, 'mcp_call_limit_override' => 42]);

    expect($workspace->machineLimit())->toBeNull()
        ->and($workspace->mcpCallLimit())->toBe(42);
});

test('a self-hosted workspace is active without a plan, with the free features and no paid ones', function () {
    useEdition('self-hosted');

    $workspace = Workspace::factory()->create(['plan' => null, 'trial_ends_at' => now()->subYear()]);

    expect($workspace->isActive())->toBeTrue()
        ->and($workspace->hasFeature(Feature::StaffRoles))->toBeTrue()
        ->and($workspace->hasFeature(Feature::StatusPages))->toBeTrue()
        ->and($workspace->hasFeature(Feature::Automations))->toBeTrue()
        ->and($workspace->hasSso())->toBeFalse()
        ->and($workspace->hasScim())->toBeFalse()
        ->and($workspace->hasCustomRoles())->toBeFalse()
        ->and($workspace->hasCustomDomains())->toBeFalse()
        ->and($workspace->hasFleetStatusPage())->toBeFalse()
        ->and($workspace->canAddMachine())->toBeTrue()
        ->and($workspace->machineLimit())->toBeNull()
        ->and($workspace->mcpCallLimit())->toBeNull()
        ->and($workspace->logRetentionDays())->toBe(30)
        ->and($workspace->monitorLimit())->toBe(1_000)
        ->and($workspace->minMonitorInterval())->toBe(30);
});

test('a self-hosted install can set its own numbers', function () {
    useEdition('self-hosted');
    config(['serveros.self_hosted.log_retention_days' => 90, 'serveros.self_hosted.machines' => 3]);

    $workspace = Workspace::factory()->create(['plan' => null]);

    expect($workspace->logRetentionDays())->toBe(90)
        ->and($workspace->machineLimit())->toBe(3);
});
