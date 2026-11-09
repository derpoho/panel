<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Auth\MailExchangers;
use App\Support\Auth\Turnstile;
use App\Support\WorkspaceSlugs;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function registrationData(array $overrides = []): array
{
    return [
        'name' => 'Alex Rivera',
        'email' => 'alex@northwind.dev',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

test('users can pick their workspace name when registering', function () {
    $this->post(route('register.store'), registrationData(['workspace' => 'Northwind']))
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::firstWhere('email', 'alex@northwind.dev');
    $workspace = $user->defaultWorkspace();

    expect($workspace->slug)->toBe('northwind')
        ->and($workspace->name)->toBe('Northwind')
        ->and($workspace->owner->is($user))->toBeTrue()
        ->and($workspace->members()->first()->pivot->role)->toBe('owner');
});

test('a workspace is generated from the name when none is picked', function () {
    $this->post(route('register.store'), registrationData())->assertSessionHasNoErrors();

    expect(User::firstWhere('email', 'alex@northwind.dev')->defaultWorkspace()->slug)->toBe('alex-rivera');
});

test('a generated workspace avoids names that are taken', function () {
    Workspace::factory()->create(['slug' => 'alex-rivera']);

    $this->post(route('register.store'), registrationData())->assertSessionHasNoErrors();

    $slug = User::firstWhere('email', 'alex@northwind.dev')->defaultWorkspace()->slug;

    expect($slug)->not->toBe('alex-rivera')->toStartWith('alex-rivera-');
});

test('taken workspace names are rejected with suggestions', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->post(route('register.store'), registrationData(['workspace' => 'northwind']))
        ->assertSessionHasErrors(['workspace' => 'That workspace name is taken. Try northwind-hq or northwind-team.']);

    $this->assertGuest();
    expect(User::where('email', 'alex@northwind.dev')->exists())->toBeFalse();
});

test('reserved and malformed workspace names are rejected', function (string $slug) {
    $this->post(route('register.store'), registrationData(['workspace' => $slug]))
        ->assertSessionHasErrors('workspace');
})->with(['www', 'admin', 'ab', 'north.wind', 'north_wind', 'north wind', str_repeat('a', 33)]);

test('the availability check reports free names', function () {
    $this->getJson(route('workspaces.availability', ['slug' => 'Northwind']))
        ->assertOk()
        ->assertExactJson([
            'slug' => 'northwind',
            'available' => true,
            'message' => null,
            'suggestions' => [],
        ]);
});

test('the availability check suggests free alternatives for taken names', function () {
    Workspace::factory()->create(['slug' => 'northwind']);
    Workspace::factory()->create(['slug' => 'northwind-hq']);

    $response = $this->getJson(route('workspaces.availability', ['slug' => 'northwind']))
        ->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('message', 'That workspace name is taken.')
        ->assertJsonCount(3, 'suggestions');

    $suggestions = $response->json('suggestions');

    expect($suggestions)->not->toContain('northwind-hq')
        ->and(collect($suggestions)->every(fn (string $slug) => app(WorkspaceSlugs::class)->isAvailable($slug)))->toBeTrue();
});

test('suggestions stay within the length limit', function () {
    $long = str_repeat('a', 32);
    Workspace::factory()->create(['slug' => $long]);

    $suggestions = app(WorkspaceSlugs::class)->suggest($long);

    expect($suggestions)->toHaveCount(3)
        ->each(fn ($slug) => $slug->toMatch(WorkspaceSlugs::PATTERN)->and(strlen($slug->value))->toBeLessThanOrEqual(32));
});

test('signing up celebrates the trial once, on the first workspace page', function () {
    $this->post(route('register.store'), [
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'sam@example.com')->sole();
    $user->markEmailAsVerified();
    $this->actingAs($user->fresh());
    $workspace = $user->workspaces()->sole();
    $home = 'https://'.$workspace->host().'/';

    // A new account goes through the welcome flow first, and the
    // celebration waits for the dashboard.
    $this->get($home)->assertRedirect($home.'welcome');
    $this->get($home.'welcome')->assertInertia(fn ($page) => $page->where('celebrate', false));
    $this->post($home.'welcome/finish')->assertRedirect(rtrim($home, '/'));

    $this->get($home)->assertInertia(fn ($page) => $page->where('celebrate', true));
    $this->get($home)->assertInertia(fn ($page) => $page->where('celebrate', false));
});

test('throwaway inboxes can’t sign up, by their domain or by the mail service behind it', function () {
    $this->post(route('register.store'), registrationData(['email' => 'rtq7m2x9@maxxspace.com']))
        ->assertSessionHasErrors(['email' => 'Use an email address you keep. Temporary inboxes can’t be used for a ServerOS account.']);
    $this->post(route('register.store'), registrationData(['email' => 'someone@mail.yopmail.com']))->assertSessionHasErrors('email');

    // A domain no list has yet, on mail.tm's servers.
    app(MailExchangers::class)->hosts['brand-new-temp.xyz'] = ['in.mail.tm'];
    $this->post(route('register.store'), registrationData(['email' => 'a@brand-new-temp.xyz']))->assertSessionHasErrors('email');

    // Ordinary mail is fine, wherever it's hosted.
    app(MailExchangers::class)->hosts['acme.dev'] = ['aspmx.l.google.com'];
    $this->post(route('register.store'), registrationData(['email' => 'sam@acme.dev']))->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'like', '%maxxspace%')->exists())->toBeFalse();
});

test('one network can only make a few accounts an hour', function () {
    foreach (range(1, CreateNewUser::SIGNUPS_PER_HOUR) as $i) {
        $this->post(route('register.store'), registrationData(['email' => "person{$i}@acme.dev", 'workspace' => "team-{$i}"]))->assertSessionHasNoErrors();
        auth()->logout();
    }

    $this->post(route('register.store'), registrationData(['email' => 'one-more@acme.dev', 'workspace' => 'one-more']))
        ->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'one-more@acme.dev')->exists())->toBeFalse();
});

test('an email can’t be changed to a throwaway inbox either', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'x@maxxspace.com'])
        ->assertSessionHasErrors('email');
});

test('with Turnstile set up, signing up needs Cloudflare to say it was a person', function () {
    config()->set('services.turnstile', ['site_key' => '0x4AAAAAAAsite', 'secret_key' => '0x4AAAAAAAsecret']);
    Http::fake([Turnstile::VERIFY_URL => Http::sequence()
        ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
        ->push(['success' => true])]);

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', '0x4AAAAAAAsite'));

    $this->post(route('register.store'), registrationData(['email' => 'bot@acme.dev', 'cf-turnstile-response' => 'bad']))
        ->assertSessionHasErrors('turnstile');
    $this->post(route('register.store'), registrationData(['email' => 'sam@acme.dev', 'cf-turnstile-response' => 'good']))
        ->assertSessionHasNoErrors();

    expect(User::query()->pluck('email')->all())->toBe(['sam@acme.dev']);
    Http::assertSent(fn ($request) => $request->url() === Turnstile::VERIFY_URL
        && $request['secret'] === '0x4AAAAAAAsecret' && $request['response'] === 'good');
});

test('without Turnstile keys, signing up asks Cloudflare nothing', function () {
    config()->set('services.turnstile', ['site_key' => null, 'secret_key' => null]);
    Http::fake();

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', null));
    $this->post(route('register.store'), registrationData())->assertSessionHasNoErrors();

    Http::assertNotSent(fn ($request) => $request->url() === Turnstile::VERIFY_URL);
});
