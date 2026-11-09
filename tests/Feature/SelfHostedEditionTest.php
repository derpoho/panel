<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Support\Edition;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use App\Support\SetupGuide;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Make this a self-hosted install (SERVEROS_EDITION=self-hosted), as at
 * boot, already set up (InstallerTest covers the installer).
 */
function selfHostedInstall(): void
{
    config(['serveros.edition' => 'self-hosted']);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => new SelfHostedEntitlements);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
}

function selfHostedUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/'.$path;
}

test('serveros.com is not self-hosted unless the edition says so', function () {
    expect(Edition::selfHosted())->toBeFalse()
        ->and(app(Entitlements::class))->toBeInstanceOf(PlanEntitlements::class);

    selfHostedInstall();

    expect(Edition::selfHosted())->toBeTrue();
});

test('a self-hosted install opens on creating its first account, then on signing in, then on the dashboard', function () {
    selfHostedInstall();

    $this->get('/')->assertRedirect(route('register'));

    $user = User::factory()->create();
    $this->get('/')->assertRedirect(route('login'));
    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
});

test('signing up on serveros.com starts the trial and asks for the email to be checked', function () {
    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $workspace = $user->ownedWorkspaces()->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->trial_used_at)->not->toBeNull()
        ->and($workspace->onTrial())->toBeTrue()
        ->and($workspace->billingSummary())->not->toHaveKey('selfHosted');
});

test('the first account on a self-hosted install is its verified owner, with a workspace that has no trial', function () {
    selfHostedInstall();

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $owner = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();

    expect($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->trial_used_at)->toBeNull()
        ->and($workspace->trial_ends_at)->toBeNull()
        ->and($workspace->signup_variant)->toBeNull()
        // Still the welcome flow, without its plan step.
        ->and($workspace->onboarded_at)->toBeNull()
        ->and($workspace->isActive())->toBeTrue()
        ->and($workspace->billingSummary())->toMatchArray(['plan' => null, 'onTrial' => false, 'selfHosted' => true]);

    // Everyone after the owner checks their email like anywhere else.
    auth()->logout();
    $this->post(route('register.store'), [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
    expect(User::query()->where('email', 'grace@example.com')->firstOrFail()->hasVerifiedEmail())->toBeFalse();
});

test('the sign-up page offers no trial on a self-hosted install', function () {
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Register')
        ->missing('selfHosted'));

    selfHostedInstall();

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Register')
        ->where('selfHosted', true));
});

test('only the first workspace someone owns on a self-hosted install starts with the welcome flow', function () {
    selfHostedInstall();
    $user = User::factory()->create();

    $first = app(CreateWorkspace::class)->create($user, name: 'First');
    $second = app(CreateWorkspace::class)->create($user->fresh(), name: 'Second');

    expect($first->onboarded_at)->toBeNull()
        ->and($second->onboarded_at)->not->toBeNull()
        ->and($second->trial_ends_at)->toBeNull()
        ->and(CreateWorkspace::getsTrial($user->fresh()))->toBeFalse();
});

test('the welcome flow has no plan step on a self-hosted install', function () {
    $workspace = Workspace::factory()->welcoming()->create();

    $this->actingAs($workspace->owner)->get(selfHostedUrl($workspace, 'welcome'))
        ->assertInertia(fn (Assert $page) => $page->component('welcome/Welcome')->missing('choosePlan'));

    selfHostedInstall();

    $this->actingAs($workspace->owner)->get(selfHostedUrl($workspace, 'welcome'))
        ->assertInertia(fn (Assert $page) => $page->component('welcome/Welcome')->where('choosePlan', false));
    $this->actingAs($workspace->owner)
        ->post(selfHostedUrl($workspace, 'welcome/plan'), ['plan' => 'business', 'interval' => 'annual'])
        ->assertNotFound();
    $this->actingAs($workspace->owner)
        ->postJson(selfHostedUrl($workspace, 'welcome/card'), ['plan' => 'business', 'interval' => 'annual'])
        ->assertNotFound();

    $this->actingAs($workspace->owner)
        ->post(selfHostedUrl($workspace, 'welcome/finish'), ['skipped_at' => 'connect'])
        ->assertRedirect(rtrim(selfHostedUrl($workspace), '/'));
    expect($workspace->fresh()->onboarded_at)->not->toBeNull();
});

test('the dashboard asks for no plan on a self-hosted install', function () {
    $workspace = Workspace::factory()->create();
    $guideGroups = fn () => collect(SetupGuide::for($workspace, $workspace->owner)['groups'] ?? [])->pluck('key')->all();
    $homeSteps = fn () => collect($this->actingAs($workspace->owner)->get(selfHostedUrl($workspace))->viewData('page')['props']['onboarding'])->pluck('key')->all();

    // With nowhere to choose a plan, neither asks for one.
    app(Extensions::class)->setUpgradeLink(null);
    expect($homeSteps())->not->toContain('plan')
        ->and($guideGroups())->not->toContain('plan');

    // Somewhere to choose one, as a billing package adds.
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $this->actingAs($workspace->owner)->get(selfHostedUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Home')
            ->where('onboarding', fn ($steps) => collect($steps)->pluck('key')->contains('plan'))
            ->missing('workspace.billing.selfHosted'));
    expect($guideGroups())->toContain('plan');

    selfHostedInstall();

    $this->actingAs($workspace->owner)->get(selfHostedUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Home')
            ->where('onboarding', fn ($steps) => ! collect($steps)->pluck('key')->contains('plan'))
            ->where('workspace.billing.selfHosted', true));
    expect($guideGroups())->not->toContain('plan')->toContain('connect');
});

test('Seline analytics are only there in production with a project token', function () {
    $seline = '<script async src="/vendor/seline.js" data-token="abc123"></script>';
    app()->instance('env', 'production');
    config()->set('services.seline.token', 'abc123');

    // The panel's pages (app.blade.php); a package checks its own site's.
    $this->get(route('login'))->assertOk()->assertSee($seline, false);

    config()->set('services.seline.token', null);

    $this->get(route('login'))->assertOk()->assertDontSee('seline');
});

test('on serveros.com any subdomain may get a certificate, as before', function () {
    $ask = fn (string $domain) => $this->call('GET', '/api/internal/tls-check', ['domain' => $domain], [], [], ['REMOTE_ADDR' => '127.0.0.1'])->status();
    $ours = config('app.domain');

    expect($ask($ours))->toBe(200)
        ->and($ask("made-up.{$ours}"))->toBe(200)
        ->and($ask("a.b.{$ours}"))->toBe(200)
        ->and($ask('evil.test'))->toBe(404);
});

test('a self-hosted install only gets certificates for the names it serves', function () {
    selfHostedInstall();
    $ask = fn (string $domain, string $ip = '127.0.0.1') => $this->call('GET', '/api/internal/tls-check', ['domain' => $domain], [], [], ['REMOTE_ADDR' => $ip])->status();
    $ours = config('app.domain');
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    WorkspaceDomain::query()->create(['workspace_id' => $workspace->id, 'hostname' => 'ops.acme.test', 'purpose' => WorkspaceDomain::DASHBOARD, 'state' => 'active']);
    $other = Workspace::factory()->create(['slug' => 'other']);
    WorkspaceDomain::query()->create(['workspace_id' => $other->id, 'hostname' => 'old.acme.test', 'purpose' => WorkspaceDomain::DASHBOARD, 'state' => 'failed']);

    expect($ask($ours))->toBe(200)
        ->and($ask(strtoupper("acme.{$ours}")))->toBe(200)
        ->and($ask("docs.{$ours}"))->toBe(200)
        ->and($ask("feedback.{$ours}"))->toBe(200)
        // A workspace's own domains.
        ->and($ask('ops.acme.test'))->toBe(200)
        ->and($ask('old.acme.test'))->toBe(404)
        // Nothing made up.
        ->and($ask("made-up.{$ours}"))->toBe(404)
        ->and($ask("x.acme.{$ours}"))->toBe(404)
        ->and($ask("status.{$ours}"))->toBe(404)
        ->and($ask("www.{$ours}"))->toBe(404)
        ->and($ask('evil.test'))->toBe(404)
        ->and($ask("acme.{$ours}", '203.0.113.9'))->toBe(404);

    // The status page, once there is one.
    config(['serveros.status_page' => 'acme']);
    expect($ask("status.{$ours}"))->toBe(200);
});

test('only one sign-up can be a self-hosted install’s first, verified owner', function () {
    selfHostedInstall();
    $signUp = fn (string $email) => $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // Another sign-up gets the lock first and commits its account while this
    // one waits: once it has the lock, this one is no longer first.
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andReturnUsing(function (int $seconds, Closure $callback) {
        User::factory()->create(['email' => 'first@example.com', 'email_verified_at' => now()]);

        return $callback();
    });
    Cache::partialMock()->shouldReceive('lock')->once()->with(CreateNewUser::FIRST_OWNER_LOCK, 30)->andReturn($lock);

    $signUp('second@example.com');

    expect(User::query()->count())->toBe(2)
        ->and(User::query()->where('email', 'second@example.com')->firstOrFail()->hasVerifiedEmail())->toBeFalse();
});

test('the first-owner lock is only taken on a self-hosted install with no accounts yet', function () {
    Cache::partialMock()->shouldNotReceive('lock');

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::query()->where('email', 'ada@example.com')->firstOrFail()->hasVerifiedEmail())->toBeFalse();
});
