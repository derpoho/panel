<?php

use App\Models\User;
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

function connectingAs(string $provider, string $id, string $email): void
{
    $account = (new ProviderUser)->setRaw(['email_verified' => true])->map(['id' => $id, 'name' => 'Ada', 'email' => $email]);
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($account);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.example.test/auth'));
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with($provider)->andReturn($driver);
    Socialite::swap($socialite);
}

test('a Google sign-up can set a password without a current one, then needs it', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasPassword', false));

    $this->actingAs($user)->put(route('user-password.update'), [
        'password' => 'a-brand-new-Passw0rd!',
        'password_confirmation' => 'a-brand-new-Passw0rd!',
    ])->assertSessionHasNoErrors();
    expect($user->refresh()->password_set_at)->not->toBeNull();

    $this->actingAs($user)->put(route('user-password.update'), [
        'password' => 'another-Passw0rd!x',
        'password_confirmation' => 'another-Passw0rd!x',
    ])->assertSessionHasErrors('current_password');
});

test('someone with a password still confirms it before Security', function () {
    $this->actingAs(User::factory()->create())->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('connecting GitHub from Settings links it, and Google can then be disconnected', function () {
    $user = User::factory()->withoutPassword()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'g-1', 'email' => $user->email]);

    // The only way in: can't be disconnected yet.
    $this->actingAs($user)->get(route('security.edit'))
        ->assertInertia(fn ($page) => $page->where('connectedAccounts.0.provider', 'google')->where('connectedAccounts.0.canDisconnect', false));
    $this->actingAs($user)->delete(route('social.disconnect', 'google'));
    expect($user->socialAccounts()->count())->toBe(1);

    connectingAs('github', 'gh-9', 'ada@users.github.test');
    $this->actingAs($user)->from(route('security.edit'))->get(route('social.connect', 'github'))->assertRedirect('https://accounts.example.test/auth');
    $this->actingAs($user)->get('/auth/github/callback')->assertRedirect(route('security.edit'));
    expect($user->socialAccounts()->where('provider', 'github')->value('provider_id'))->toBe('gh-9');

    $this->actingAs($user)->delete(route('social.disconnect', 'google'))->assertRedirect();
    expect($user->socialAccounts()->pluck('provider')->all())->toBe(['github']);
});

test('an account already linked to someone else is not taken over', function () {
    $other = User::factory()->create();
    $other->socialAccounts()->create(['provider' => 'github', 'provider_id' => 'gh-9', 'email' => 'x@example.com']);
    $user = User::factory()->create();

    connectingAs('github', 'gh-9', 'x@example.com');
    $this->actingAs($user)->session(['auth.password_confirmed_at' => time()])->get(route('social.connect', 'github'));
    $this->actingAs($user)->get('/auth/github/callback')->assertRedirect(route('security.edit'));

    expect($user->socialAccounts()->count())->toBe(0)
        ->and($other->socialAccounts()->count())->toBe(1);
});

test('a signed-in visit to the callback without connecting just goes to the dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/auth/google/callback')->assertRedirect();
});
