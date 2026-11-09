<?php

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Support\Auth\TxtRecords;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    // No Google sign-in on this deployment.
    config()->set('services.google.client_id', null);
    config()->set('services.google.client_secret', null);
});

function vdHost(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

function vdSecurity(Workspace $workspace, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? $workspace->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(vdHost($workspace, '/settings/security'));
}

/** Adds the domain through the settings and verifies it with the fake DNS. */
function vdVerify(Workspace $workspace, string $name = 'acme.com'): WorkspaceEmailDomain
{
    test()->actingAs($workspace->owner)
        ->post(vdHost($workspace, '/settings/email-domains'), ['domain' => $name])
        ->assertSessionHasNoErrors();
    $domain = $workspace->emailDomains()->where('domain', $name)->sole();

    app(TxtRecords::class)->records[$domain->recordName()] = [$domain->recordValue()];
    test()->actingAs($workspace->owner)->post(vdHost($workspace, "/settings/email-domains/{$domain->id}/check"));

    return $domain->fresh();
}

test('verified domains show on Pro and Business without Google sign-in set up', function (Plan $plan) {
    $workspace = Workspace::factory()->onPlan($plan)->create();
    $workspace->emailDomains()->create(['domain' => 'acme.com']);

    vdSecurity($workspace)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/settings/Security')
        ->where('googleSignIn', null)
        ->where('emailDomains.available', true)
        ->has('emailDomains.domains', 1)
        ->where('emailDomains.domains.0.domain', 'acme.com')
        ->where('emailDomains.domains.0.verified', false)
        ->where('emailDomains.domains.0.record.name', '_serveros-verify.acme.com'));
})->with([Plan::Pro, Plan::Business]);

test('verified domains aren’t shown below Pro or to people who can’t manage settings', function () {
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    vdSecurity($essential)->assertInertia(fn (Assert $page) => $page->where('emailDomains', null));

    $business = Workspace::factory()->onPlan(Plan::Business)->create();
    $member = User::factory()->create();
    $business->members()->attach($member, ['role' => 'member']);
    vdSecurity($business, $member)->assertInertia(fn (Assert $page) => $page->where('emailDomains', null));
});

test('with Google sign-in set up, its section has only the verified domains', function () {
    config()->set('services.google.client_id', 'id');
    config()->set('services.google.client_secret', 'secret');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->emailDomains()->create(['domain' => 'acme.com'])->forceFill(['verified_at' => now()])->save();
    $workspace->emailDomains()->create(['domain' => 'acme-labs.com']);

    vdSecurity($workspace)->assertInertia(fn (Assert $page) => $page
        ->has('emailDomains.domains', 2)
        ->where('googleSignIn.verifiedDomains', ['acme.com'])
        ->missing('googleSignIn.domains'));
});
