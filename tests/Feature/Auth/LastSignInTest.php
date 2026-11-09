<?php

use App\Models\User;
use App\Support\LastSignIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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

/** What the response left in the "Last used" cookie, if it set one. */
function lastSignInCookie($response): ?string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === LastSignIn::COOKIE);

    return $cookie === null ? null : decrypt($cookie->getValue(), false);
}

function signsInWith(string $provider): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn((new ProviderUser)->setRaw(['email_verified' => true])
        ->map(['id' => '123', 'name' => 'Ada', 'nickname' => 'ada', 'email' => 'ada@example.com', 'avatar' => null]));
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with($provider)->andReturn($driver);
    Socialite::swap($socialite);
}

test('a password sign-in is remembered, and stays after signing out', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    expect(lastSignInCookie($response))->toContain('password');

    // A new request starts with nothing queued; signing out doesn't clear it.
    Cookie::flushQueuedCookies();
    expect(lastSignInCookie($this->post(route('logout'))))->toBeNull();
});

test('a wrong password leaves nothing', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);

    expect(lastSignInCookie($response))->toBeNull();
});

test('Google and GitHub sign-ins are remembered by name', function (string $provider) {
    signsInWith($provider);

    $response = $this->get("/auth/{$provider}/callback");

    $this->assertAuthenticated();
    expect(lastSignInCookie($response))->toContain($provider);
})->with(['google', 'github']);

test('through the code prompt, Google still counts as Google and a recovery code as the password', function () {
    $codes = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $codes->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $codes);
    $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);
    signsInWith('google');

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));
    expect(lastSignInCookie($this->post(route('two-factor.login.store'), ['code' => '123456'])))->toContain('google');

    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    expect(lastSignInCookie($this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])))->toContain('password');
});

test('signing in outside the sign-in routes, as View as does, leaves it alone', function () {
    $user = User::factory()->create();

    $response = $this->get(route('login'));
    Auth::login($user);

    expect(lastSignInCookie($response))->toBeNull()
        ->and(Cookie::getQueuedCookies())->toBeEmpty();
});

test('the sign-in page marks the remembered method', function (string $method) {
    $this->withCookie(LastSignIn::COOKIE, $method)->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('lastUsed', $method));
})->with(['password', 'passkey', 'google', 'github']);

test('the sign-in page ignores a method it no longer offers, or one it never knew', function () {
    config()->set('services.github.client_id', null);

    $this->withCookie(LastSignIn::COOKIE, 'github')->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('lastUsed', null));
    $this->withCookie(LastSignIn::COOKIE, 'carrier-pigeon')->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('lastUsed', null));
    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('lastUsed', null));
});
