<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Models\WorkspaceJoinRequest;
use App\Notifications\WorkspaceJoinRequested;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Auth\TxtRecords;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    foreach (['google', 'github'] as $provider) {
        config()->set("services.{$provider}.client_id", 'id');
        config()->set("services.{$provider}.client_secret", 'secret');
    }
});

function gtaHost(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

/** The Google account handed back: `hd` is its Google Workspace domain, if any. */
function googleAccount(string $email, ?string $hd, bool $verified = true, string $id = 'g-1'): ProviderUser
{
    return (new ProviderUser)
        ->setRaw(array_filter(['email_verified' => $verified, 'hd' => $hd], fn ($v) => $v !== null))
        ->map(['id' => $id, 'name' => 'Ada Lovelace', 'nickname' => 'ada', 'email' => $email, 'avatar' => null]);
}

function googleReturns(ProviderUser $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($driver);

    Socialite::swap($socialite);
}

/** The fake DNS from TestCase. */
function txtFake(): TxtRecords
{
    return app(TxtRecords::class);
}

/** A workspace with acme.com verified, joining set as given. */
function verifiedDomainFor(Plan $plan = Plan::Pro, string $join = 'auto', string $domain = 'acme.com', array $attributes = []): Workspace
{
    $workspace = Workspace::factory()->onPlan($plan)->create();
    $workspace->forceFill(['domain_join' => $join, ...$attributes])->save();
    $workspace->emailDomains()->create(['domain' => $domain])->forceFill(['verified_at' => now()])->save();

    return $workspace;
}

test('a domain is added, then verified by its TXT record', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)
        ->post(gtaHost($workspace, '/settings/email-domains'), ['domain' => ' https://Acme.COM/ '])
        ->assertSessionHasNoErrors();

    $domain = WorkspaceEmailDomain::query()->sole();
    expect($domain->domain)->toBe('acme.com')
        ->and($domain->isVerified())->toBeFalse()
        ->and($domain->recordName())->toBe('_serveros-verify.acme.com')
        ->and($domain->recordValue())->toStartWith('serveros-domain-verification=');

    // Not there yet.
    $this->actingAs($workspace->owner)->post(gtaHost($workspace, "/settings/email-domains/{$domain->id}/check"));
    expect($domain->fresh()->isVerified())->toBeFalse()
        ->and($domain->fresh()->checked_at)->not->toBeNull();

    txtFake()->records['_serveros-verify.acme.com'] = ['v=spf1 -all', $domain->recordValue()];
    $this->actingAs($workspace->owner)->post(gtaHost($workspace, "/settings/email-domains/{$domain->id}/check"));

    expect($domain->fresh()->isVerified())->toBeTrue()
        ->and(Activity::query()->where('workspace_id', $workspace->id)->pluck('action')->all())
        ->toContain('email_domain.add', 'email_domain.verified');
});

test('the record works on the domain itself too', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $domain = $workspace->emailDomains()->create(['domain' => 'acme.com']);
    txtFake()->records['acme.com'] = ['"'.$domain->recordValue().'"'];

    $this->actingAs($workspace->owner)->post(gtaHost($workspace, "/settings/email-domains/{$domain->id}/check"));

    expect($domain->fresh()->isVerified())->toBeTrue();
});

test('public mail domains and ours are refused', function (string $input) {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)
        ->post(gtaHost($workspace, '/settings/email-domains'), ['domain' => $input])
        ->assertSessionHasErrors('domain');

    expect(WorkspaceEmailDomain::count())->toBe(0);
})->with(['gmail.com', 'Outlook.com', 'yahoo.com', 'icloud.com', 'proton.me', 'serveros.test', 'acme.serveros.test', 'not a domain']);

test('a domain verified by another workspace cannot be verified again', function () {
    $other = verifiedDomainFor();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)
        ->post(gtaHost($workspace, '/settings/email-domains'), ['domain' => 'acme.com'])
        ->assertSessionHasErrors('domain');

    // Added before the other workspace verified it: the record isn't enough.
    $mine = $workspace->emailDomains()->create(['domain' => 'acme.com']);
    txtFake()->records['_serveros-verify.acme.com'] = [$mine->recordValue()];
    $this->actingAs($workspace->owner)->post(gtaHost($workspace, "/settings/email-domains/{$mine->id}/check"));

    expect($mine->fresh()->isVerified())->toBeFalse()
        ->and($other->emailDomains()->sole()->isVerified())->toBeTrue();
});

test('verified domains need Pro or Business, and settings rights', function () {
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    $this->actingAs($essential->owner)
        ->post(gtaHost($essential, '/settings/email-domains'), ['domain' => 'acme.com'])
        ->assertSessionHasErrors('domain');

    $pro = Workspace::factory()->onPlan(Plan::Pro)->create();
    $member = User::factory()->create();
    $pro->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member)
        ->post(gtaHost($pro, '/settings/email-domains'), ['domain' => 'acme.com'])
        ->assertForbidden();

    expect(WorkspaceEmailDomain::count())->toBe(0);
});

test('the daily check unverifies a domain whose record is gone, but not on a DNS hiccup', function () {
    $workspace = verifiedDomainFor();
    $domain = $workspace->emailDomains()->sole();
    $kept = verifiedDomainFor(domain: 'globex.com')->emailDomains()->sole();
    txtFake()->records['_serveros-verify.globex.com'] = [$kept->recordValue()];
    $flaky = verifiedDomainFor(domain: 'initech.com')->emailDomains()->sole();
    txtFake()->failing = ['_serveros-verify.initech.com', 'initech.com'];

    $this->artisan('email-domains:recheck')->assertSuccessful();

    expect($domain->fresh()->isVerified())->toBeFalse()
        ->and($kept->fresh()->isVerified())->toBeTrue()
        ->and($flaky->fresh()->isVerified())->toBeTrue()
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'email_domain.unverified')->exists())->toBeTrue();
});

test('a Google account on a verified domain joins with the chosen role and lands there', function () {
    $workspace = verifiedDomainFor(attributes: ['domain_join_role' => 'viewer']);
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));

    $response = $this->get('/auth/google/callback');

    $user = User::query()->where('email', 'ada@acme.com')->sole();
    $response->assertRedirect($workspace->url());
    expect($workspace->roleOf($user)?->value)->toBe('viewer')
        ->and(session(GoogleTeamAccess::VIA))->toBe('google')
        ->and(session(GoogleTeamAccess::HD))->toBe('acme.com');

    $entry = Activity::query()->where('workspace_id', $workspace->id)->where('action', 'member.join')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->meta)->toMatchArray(['via' => 'google_domain', 'domain' => 'acme.com', 'role' => 'Viewer']);
});

test('somewhere they were going already wins', function () {
    $workspace = verifiedDomainFor();
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));

    $this->withSession(['url.intended' => 'https://serveros.test/settings/profile'])
        ->get('/auth/google/callback')
        ->assertRedirect('https://serveros.test/settings/profile');

    expect($workspace->members()->where('email', 'ada@acme.com')->exists())->toBeTrue();
});

test('nobody joins without a matching, verified Google Workspace domain', function (ProviderUser $account, ?string $hd) {
    verifiedDomainFor();
    $unverified = Workspace::factory()->onPlan(Plan::Pro)->create();
    $unverified->forceFill(['domain_join' => 'auto'])->save();
    $unverified->emailDomains()->create(['domain' => 'globex.com']);
    User::factory()->create(['email' => $account->getEmail()]);
    googleReturns($account);

    $this->get('/auth/google/callback');

    $user = User::query()->where('email', $account->getEmail())->sole();
    expect($user->workspaces()->count())->toBe(0)
        ->and(WorkspaceJoinRequest::count())->toBe(0)
        // The session keeps a real Google Workspace domain, verified by a workspace or not.
        ->and(session(GoogleTeamAccess::HD))->toBe($hd);
})->with([
    'hd for another domain' => [fn () => googleAccount('ada@acme.com', 'other.com'), null],
    'a personal account on the domain, no hd' => [fn () => googleAccount('ada@acme.com', null), null],
    'hd without the email matching' => [fn () => googleAccount('ada@gmail.com', 'acme.com'), null],
    'a domain nobody verified' => [fn () => googleAccount('ada@globex.com', 'globex.com'), 'globex.com'],
]);

test('an unverified Google email never gets as far as joining', function () {
    $workspace = verifiedDomainFor();
    googleReturns(googleAccount('ada@acme.com', 'acme.com', verified: false));

    $this->get('/auth/google/callback')->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect($workspace->members()->count())->toBe(1);
});

test('joining as a custom role sits it on top of Viewer, on Business', function () {
    $workspace = verifiedDomainFor(Plan::Business);
    $role = $workspace->customRoles()->create(['name' => 'Ops', 'permissions' => ['manage-machines']]);

    $this->actingAs($workspace->owner)
        ->put(gtaHost($workspace, '/settings/domain-join'), ['mode' => 'auto', 'role' => "custom:{$role->id}"])
        ->assertSessionHasNoErrors();
    auth()->logout();

    googleReturns(googleAccount('ada@acme.com', 'acme.com'));
    $this->get('/auth/google/callback');

    $user = User::query()->where('email', 'ada@acme.com')->sole();
    expect($workspace->roleOf($user)?->value)->toBe('viewer')
        ->and($workspace->customRoleOf($user)?->is($role))->toBeTrue();
});

test('the join role follows the plan: staff roles on Pro, custom roles on Business', function () {
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    $this->actingAs($essential->owner)
        ->put(gtaHost($essential, '/settings/domain-join'), ['mode' => 'auto', 'role' => 'admin'])
        ->assertSessionHasErrors('mode');

    $pro = Workspace::factory()->onPlan(Plan::Pro)->create();
    $role = $pro->customRoles()->create(['name' => 'Ops', 'permissions' => []]);
    $this->actingAs($pro->owner)
        ->put(gtaHost($pro, '/settings/domain-join'), ['mode' => 'auto', 'role' => "custom:{$role->id}"])
        ->assertSessionHasErrors('role');
    $this->actingAs($pro->owner)
        ->put(gtaHost($pro, '/settings/domain-join'), ['mode' => 'auto', 'role' => 'owner'])
        ->assertSessionHasErrors('role');
    $this->actingAs($pro->owner)
        ->put(gtaHost($pro, '/settings/domain-join'), ['mode' => 'request', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    expect($pro->fresh()->domain_join)->toBe('request')
        ->and(Activity::query()->where('workspace_id', $pro->id)->where('action', 'sign_in.domain_join')->exists())->toBeTrue();
});

test('a workspace that left Pro stops letting people in by domain', function () {
    $workspace = verifiedDomainFor();
    $workspace->forceFill(['plan' => Plan::Essential])->save();
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));

    $this->get('/auth/google/callback');

    expect($workspace->members()->count())->toBe(1);
});

test('asking to join: admins hear about it and decide', function () {
    Notification::fake();
    $workspace = verifiedDomainFor(join: 'request');
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));

    $this->get('/auth/google/callback')->assertRedirect('https://serveros.test/join-requests');

    $ada = User::query()->where('email', 'ada@acme.com')->sole();
    $request = WorkspaceJoinRequest::query()->sole();
    expect($workspace->hasMember($ada))->toBeFalse()
        ->and($request->state)->toBe('pending')
        ->and($request->domain)->toBe('acme.com');
    Notification::assertSentTo([$workspace->owner, $admin], WorkspaceJoinRequested::class);

    $this->get('https://serveros.test/join-requests')
        ->assertInertia(fn (Assert $page) => $page->component('auth/JoinRequested')->where('workspaces', [$workspace->name]));

    // Signing in again doesn't ask twice.
    auth()->logout();
    $this->get('/auth/google/callback');
    expect(WorkspaceJoinRequest::count())->toBe(1);

    $this->actingAs($admin)
        ->get(gtaHost($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page->has('joinRequests', 1)->where('joinRequests.0.email', 'ada@acme.com'));

    $this->actingAs($admin)
        ->post(gtaHost($workspace, "/settings/join-requests/{$request->id}/approve"), ['role' => 'member'])
        ->assertSessionHasNoErrors();

    expect($workspace->roleOf($ada)?->value)->toBe('member')
        ->and($request->fresh()->state)->toBe('approved')
        ->and($request->fresh()->decided_by)->toBe($admin->id)
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'member.request_approve')->value('meta'))
        ->toMatchArray(['via' => 'google_domain_request', 'role' => 'Member']);
});

test('a request turned down stays down; members cannot decide', function () {
    $workspace = verifiedDomainFor(join: 'request');
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));
    $this->get('/auth/google/callback');
    auth()->logout();
    $request = WorkspaceJoinRequest::query()->sole();

    $this->actingAs($member)
        ->post(gtaHost($workspace, "/settings/join-requests/{$request->id}/approve"), ['role' => 'member'])
        ->assertForbidden();

    $this->actingAs($workspace->owner)
        ->post(gtaHost($workspace, "/settings/join-requests/{$request->id}/deny"))
        ->assertSessionHasNoErrors();
    expect($request->fresh()->state)->toBe('denied')
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'member.request_deny')->exists())->toBeTrue();

    auth()->logout();
    $this->get('/auth/google/callback');
    expect(WorkspaceJoinRequest::query()->pending()->count())->toBe(0)
        ->and($workspace->members()->where('email', 'ada@acme.com')->exists())->toBeFalse();
});

test('someone removed does not come straight back through the domain', function () {
    $workspace = verifiedDomainFor();
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));
    $this->get('/auth/google/callback');
    $ada = User::query()->where('email', 'ada@acme.com')->sole();
    expect($workspace->hasMember($ada))->toBeTrue();
    auth()->logout();

    $this->actingAs($workspace->owner)->delete(gtaHost($workspace, "/settings/members/{$ada->id}"));
    auth()->logout();

    $this->get('/auth/google/callback');
    expect($workspace->hasMember($ada))->toBeFalse();
});

test('through the code prompt, the person still joins once the code is right', function () {
    $codes = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $codes->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $codes);

    $workspace = verifiedDomainFor();
    $ada = User::factory()->withTwoFactor()->create(['email' => 'ada@acme.com']);
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));
    expect($workspace->hasMember($ada))->toBeFalse();

    $this->post(route('two-factor.login.store'), ['code' => '000000']);
    expect($workspace->hasMember($ada))->toBeFalse();

    $this->post(route('two-factor.login.store'), ['code' => '123456'])->assertRedirect($workspace->url());

    expect($workspace->hasMember($ada))->toBeTrue()
        ->and(session(GoogleTeamAccess::VIA))->toBe('google')
        ->and(session(GoogleTeamAccess::HD))->toBe('acme.com');
});

test('a password sign-in after a Google try at the code prompt carries no domain', function () {
    $workspace = verifiedDomainFor();
    $ada = User::factory()->withTwoFactor()->create(['email' => 'ada@acme.com']);
    googleReturns(googleAccount('ada@acme.com', 'acme.com'));
    $this->get('/auth/google/callback');

    // Gives up on the code prompt and signs in with a password instead.
    $ada->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $this->post(route('login.store'), ['email' => 'ada@acme.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($ada);
    expect(session(GoogleTeamAccess::VIA))->toBe('password')
        ->and(session(GoogleTeamAccess::HD))->toBeNull()
        ->and($workspace->hasMember($ada))->toBeFalse();
});

test('when Google is required, a password session is sent through Google', function () {
    $workspace = verifiedDomainFor(join: 'off', attributes: ['require_google_sign_in' => true]);
    $sam = User::factory()->create(['email' => 'sam@acme.com']);
    $workspace->members()->attach($sam, ['role' => 'admin']);

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])
        ->get(gtaHost($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/GoogleSignInRequired')
            ->where('workspace', $workspace->name)
            ->where('domains', ['acme.com']));

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'google', GoogleTeamAccess::HD => 'gmail.com'])
        ->getJson(gtaHost($workspace, '/settings/members'))
        ->assertForbidden()
        ->assertJson(['code' => 'google_sign_in_required']);

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'google', GoogleTeamAccess::HD => 'acme.com'])
        ->get(gtaHost($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/Members'));
});

test('the owner always gets in, however they signed in', function () {
    $workspace = verifiedDomainFor(join: 'off', attributes: ['require_google_sign_in' => true]);

    $this->actingAs($workspace->owner)->withSession([GoogleTeamAccess::VIA => 'password', 'auth.password_confirmed_at' => time()])
        ->get(gtaHost($workspace, '/settings/security'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/Security')
            ->where('googleSignIn.requireGoogle', true));

    $this->actingAs($workspace->owner)
        ->put(gtaHost($workspace, '/settings/require-google'), ['require' => false])
        ->assertSessionHasNoErrors();
    expect($workspace->fresh()->require_google_sign_in)->toBeFalse();
});

test('turning it on needs a verified domain, and a way in for whoever does it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'admin']);
    $domain = $workspace->emailDomains()->create(['domain' => 'acme.com']);

    $this->actingAs($workspace->owner)
        ->put(gtaHost($workspace, '/settings/require-google'), ['require' => true])
        ->assertSessionHasErrors('require');

    $domain->forceFill(['verified_at' => now()])->save();

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])
        ->put(gtaHost($workspace, '/settings/require-google'), ['require' => true])
        ->assertSessionHasErrors('require');

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'google', GoogleTeamAccess::HD => 'acme.com'])
        ->put(gtaHost($workspace, '/settings/require-google'), ['require' => true])
        ->assertSessionHasNoErrors();

    expect($workspace->fresh()->require_google_sign_in)->toBeTrue()
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'sign_in.require_google_on')->exists())->toBeTrue();

    // The last domain can't go while it's required.
    $this->actingAs($workspace->owner)
        ->delete(gtaHost($workspace, "/settings/email-domains/{$domain->id}"))
        ->assertSessionHasErrors('domain');
});

test('turning it on needs Pro or Business', function () {
    $workspace = verifiedDomainFor(Plan::Essential, join: 'off');

    $this->actingAs($workspace->owner)
        ->put(gtaHost($workspace, '/settings/require-google'), ['require' => true])
        ->assertSessionHasErrors('require');
});

test('sign in with Google from the page: signed out, then back to the workspace', function () {
    $workspace = verifiedDomainFor(join: 'off', attributes: ['require_google_sign_in' => true]);
    $sam = User::factory()->create(['email' => 'sam@acme.com']);
    $workspace->members()->attach($sam, ['role' => 'member']);

    $this->actingAs($sam)->post(gtaHost($workspace, '/sign-in/google'))
        ->assertRedirect('https://serveros.test/auth/google/redirect');

    $this->assertGuest();
    expect(session('url.intended'))->toBe($workspace->url());

    // Back from Google on the company account: straight into the workspace.
    $sam->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'g-1', 'email' => 'sam@acme.com']);
    googleReturns(googleAccount('sam@acme.com', 'acme.com'));
    $this->get('/auth/google/callback')->assertRedirect($workspace->url());

    $this->get(gtaHost($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/Members'));
});

test('a GitHub sign-in clears the Google domain', function () {
    verifiedDomainFor();
    $ada = User::factory()->create(['email' => 'ada@acme.com']);
    $ada->socialAccounts()->create(['provider' => 'github', 'provider_id' => '42', 'email' => 'ada@acme.com']);

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn(googleAccount('ada@acme.com', 'acme.com', id: '42'));
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with('github')->andReturn($driver);
    Socialite::swap($socialite);

    $this->withSession([GoogleTeamAccess::HD => 'acme.com'])->get('/auth/github/callback');

    $this->assertAuthenticatedAs($ada);
    expect(session(GoogleTeamAccess::VIA))->toBe('github')
        ->and(session(GoogleTeamAccess::HD))->toBeNull()
        ->and($ada->workspaces()->count())->toBe(0);
});
