<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

/** The account Google or GitHub hands back. */
function providerUser(string $email, string $id = '123', bool $verified = true, ?string $avatar = null): ProviderUser
{
    return (new ProviderUser)
        ->setRaw(['email_verified' => $verified])
        ->map(['id' => $id, 'name' => 'Ada Lovelace', 'nickname' => 'ada', 'email' => $email, 'avatar' => $avatar]);
}

function returnsFrom(string $provider, ProviderUser $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with($provider)->andReturn($driver);

    Socialite::swap($socialite);
}

test('the buttons only show for providers with keys', function () {
    config()->set('services.github.client_id', null);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('oauthProviders', ['google']));
});

test('a provider without keys is turned away', function () {
    config()->set('services.github.client_id', null);

    $this->get('/auth/github/redirect')->assertRedirect(route('login'));
});

test('a new person gets an account and a workspace', function () {
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')->assertRedirect();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->ownedWorkspaces()->count())->toBe(1)
        ->and($user->socialAccounts()->where('provider', 'google')->value('provider_id'))->toBe('123');
});

test('an existing account with the same email is linked, then found by the link', function () {
    $user = User::factory()->create(['email' => 'Ada@Example.com']);
    returnsFrom('github', providerUser('ada@example.com', id: '42'));

    $this->get('/auth/github/callback');
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(1);

    // Later the GitHub email changes: the link still finds them.
    auth()->logout();
    returnsFrom('github', providerUser('new@example.com', id: '42'));
    $this->get('/auth/github/callback');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('an unverified Google email is refused', function () {
    returnsFrom('google', providerUser('ada@example.com', verified: false));

    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('two-factor accounts still get the code prompt', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('suspended accounts are turned away', function () {
    User::factory()->create(['email' => 'ada@example.com'])->forceFill(['suspended_at' => now()])->save();
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    $this->assertGuest();
});

test('their Google or GitHub picture becomes their profile picture', function () {
    Storage::fake('public');
    Http::fake(['lh3.googleusercontent.com/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);
    returnsFrom('google', providerUser('ada@example.com', avatar: 'https://lh3.googleusercontent.com/a/abc=s96-c'));

    $this->get('/auth/google/callback');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->avatar_path)->toEndWith('.png');
    Storage::disk('public')->assertExists($user->avatar_path);
    Http::assertSent(fn ($request) => $request->url() === 'https://lh3.googleusercontent.com/a/abc=s256-c');
});

test('a picture they chose is kept, and other hosts are never fetched', function () {
    Storage::fake('public');
    Http::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->forceFill(['avatar_path' => 'avatars/mine.png'])->save();
    returnsFrom('github', providerUser('ada@example.com', avatar: 'https://avatars.githubusercontent.com/u/1?v=4'));

    $this->get('/auth/github/callback');
    expect($user->fresh()->avatar_path)->toBe('avatars/mine.png');

    $other = User::factory()->create(['email' => 'eve@example.com']);
    returnsFrom('github', providerUser('eve@example.com', id: '9', avatar: 'https://169.254.169.254/latest'));
    auth()->logout();
    $this->get('/auth/github/callback');

    expect($other->fresh()->avatar_path)->toBeNull();
    Http::assertNothingSent();
});

test('a picture that fails to download doesn\'t stop the sign-in', function () {
    Storage::fake('public');
    Http::fake(['*' => Http::response('nope', 500)]);
    returnsFrom('github', providerUser('ada@example.com', avatar: 'https://avatars.githubusercontent.com/u/1?v=4'));

    $this->get('/auth/github/callback');

    $this->assertAuthenticated();
    expect(User::firstOrFail()->avatar_path)->toBeNull();
});

test('a callback without a code is a retry, and only a failed exchange with one is reported', function () {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andThrow(new RuntimeException('token exchange failed'));
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($driver);
    Socialite::swap($socialite);
    Exceptions::fake();

    $this->get('/auth/google/callback?error=access_denied')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    Exceptions::assertNothingReported();

    $this->get('/auth/google/callback?code=abc')->assertRedirect(route('login'));
    Exceptions::assertReported(RuntimeException::class);
    $this->assertGuest();
});
