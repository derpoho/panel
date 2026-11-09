<?php

use App\Listeners\RecordAccountEvents;
use App\Models\AccountEvent;
use App\Models\AdminAction;
use App\Models\User;
use App\Support\Audit\AccountEventLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;

/** @return list<string> the person's history as they read it, oldest first */
function accountLines(User $user): array
{
    return AccountEvent::query()->where('user_id', $user->id)->orderBy('id')->get()
        ->map(fn (AccountEvent $e) => AccountEventLine::text($e))->all();
}

function securityAdmin(): User
{
    return User::factory()->admin()->create(['name' => 'Boss']);
}

/** A row in the session table, as the database driver writes one. */
function storedSession(User $user, string $id, string $agent, array $data = []): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => $agent,
        'payload' => base64_encode(json_encode($data)),
        'last_activity' => now()->subMinutes(5)->timestamp,
    ]);
}

const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

test('signing in, a wrong password and signing out are recorded, with the device', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'wrong']);
    $this->withHeader('User-Agent', MAC_CHROME)->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password']);
    $this->post(route('logout'));

    expect(accountLines($user))->toBe([
        'Sign-in failed: wrong password',
        'Signed in with your password',
        'Signed out',
    ]);
    $signIn = AccountEvent::query()->where('event', 'signin')->sole();
    expect($signIn->method)->toBe('password')
        ->and(AccountEvent::forPerson($user)[1]['device'])->toBe('Chrome on macOS');
});

test('a wrong password for an address with no account keeps nothing, and its lockout only the IP', function () {
    foreach (range(1, 7) as $try) {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'guess'.$try]);
    }

    $lockout = AccountEvent::query()->sole();
    expect($lockout->event)->toBe('signin.locked_out')
        ->and($lockout->user_id)->toBeNull()
        ->and($lockout->email)->toBeNull()
        ->and($lockout->ip)->toBe('127.0.0.1');
});

test('a lockout on a real account is theirs, once a minute', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 8) as $try) {
        $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'guess'.$try]);
    }

    expect(AccountEvent::query()->where('user_id', $user->id)->where('event', 'signin.failed')->count())->toBe(5)
        ->and(AccountEvent::query()->where('user_id', $user->id)->where('event', 'signin.locked_out')->count())->toBe(1);

    // A minute on, another run of wrong tries is a second lockout.
    $this->travel(61)->seconds();
    foreach (range(1, 6) as $try) {
        $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'again'.$try]);
    }
    expect(AccountEvent::query()->where('user_id', $user->id)->where('event', 'signin.locked_out')->count())->toBe(2);
});

test('two-factor sign-ins say how, and a wrong code is a failed sign-in', function () {
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
    $user = User::factory()->withTwoFactor()->create(['email' => 'sam@example.com']);

    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000']);
    $this->post(route('two-factor.login.store'), ['code' => '123456']);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

    expect(accountLines($user))->toBe([
        'Sign-in failed: wrong two-factor code',
        'Signed in with your password and a two-factor code',
        'Signed out',
        'Signed in with a recovery code',
    ]);
});

test('Google sign-ins say so, through the code prompt too, and a new account says it was made', function () {
    config()->set('serveros.coming_soon', false);
    config()->set('services.google.client_id', 'id');
    config()->set('services.google.client_secret', 'secret');
    $google = fn (string $email, string $id) => (new ProviderUser)->setRaw(['email_verified' => true])
        ->map(['id' => $id, 'name' => 'Ada', 'nickname' => 'ada', 'email' => $email, 'avatar' => null]);
    $answer = null;
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturnUsing(function () use (&$answer) {
        return $answer;
    });
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($driver);
    Socialite::swap($socialite);
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturn(true);
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $answer = $google('ada@example.com', '1');
    $this->get('/auth/google/callback');
    $ada = User::query()->where('email', 'ada@example.com')->sole();
    $this->post(route('logout'));
    $this->get('/auth/google/callback');

    $sam = User::factory()->withTwoFactor()->create(['email' => 'sam@example.com']);
    $sam->socialAccounts()->create(['provider' => 'google', 'provider_id' => '2', 'email' => 'sam@example.com']);
    $this->post(route('logout'));
    $answer = $google('sam@example.com', '2');
    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => '123456']);

    expect(accountLines($ada))->toBe(['Created your account with Google', 'Signed out', 'Signed in with Google', 'Signed out'])
        ->and(accountLines($sam))->toBe(['Signed in with Google and a two-factor code']);
});

test('View as signing staff in and back out is not a sign-in, and leaving it is not a sign-out', function () {
    $sam = User::factory()->create();
    $boss = securityAdmin();

    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Helping']);
    $this->post('/admin/return');
    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Again']);
    $this->post(route('logout'));

    expect(AccountEvent::query()->count())->toBe(0);
});

test('a remembered browser coming back is not a new sign-in', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password', 'remember' => 'on']);

    $this->get(route('profile.edit'));

    expect(accountLines($user))->toBe(['Signed in with your password']);
});

test('password, two-factor and passkey changes are recorded', function () {
    $user = User::factory()->create();
    $confirmed = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-Password-123', 'password_confirmation' => 'new-Password-123']);
    // Started and cancelled before confirming: never on, so never off.
    $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.enable'));
    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'));
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.regenerate-recovery-codes'));
    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'));

    expect(accountLines($user))->toBe([
        'Changed your password',
        'Made new recovery codes',
        'Turned off two-factor sign-in',
    ]);
});

test('a password reset from the email link is recorded, and so is asking for one', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->post(route('password.email'), ['email' => 'sam@example.com']);
    $token = Password::createToken($user);
    $this->post(route('password.update'), ['token' => $token, 'email' => 'sam@example.com', 'password' => 'new-Password-123', 'password_confirmation' => 'new-Password-123']);

    expect(accountLines($user))->toBe(['Password reset link sent', 'Reset your password from an email link']);
});

test('profile, picture, connected accounts and the MCP terms are recorded once each', function () {
    Storage::fake('public');
    $user = User::factory()->create(['name' => 'Sam', 'email' => 'sam@example.com']);
    $user->socialAccounts()->create(['provider' => 'github', 'provider_id' => '9', 'email' => 'sam@example.com']);

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png')]);
    $this->actingAs($user)->delete(route('profile.avatar.destroy'));
    $this->actingAs($user)->delete(route('profile.avatar.destroy'));
    $this->actingAs($user)->post(route('mcp-terms.accept'), ['accepted' => true]);
    $this->actingAs($user)->post(route('mcp-terms.accept'), ['accepted' => true]);
    $this->actingAs($user)->delete('/settings/connections/github');
    // Last: a new address needs verifying before the other pages work.
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'sam@example.com']);
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Samantha', 'email' => 'sam@new.example.com']);

    expect(accountLines($user))->toBe([
        'Changed your profile picture',
        'Removed your profile picture',
        'Accepted the MCP terms',
        'Disconnected GitHub',
        'Changed your name to Samantha',
        'Changed your email to sam@new.example.com',
    ]);
    expect(AccountEvent::query()->where('event', 'email.changed')->sole()->meta['from'])->toBe('sam@example.com');
});

test('a deleted account keeps its history for us, by email', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

    $deleted = AccountEvent::query()->where('event', 'account.deleted')->sole();
    expect(User::query()->find($user->id))->toBeNull()
        ->and($deleted->user_id)->toBeNull()
        ->and($deleted->email)->toBe('sam@example.com');
});

test('a change made while viewing as someone is theirs as Support, and in the admin log as the admin', function () {
    Storage::fake('public');
    $sam = User::factory()->create(['name' => 'Sam']);
    $boss = securityAdmin();
    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Helping']);

    $this->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png')]);

    $event = AccountEvent::query()->sole();
    expect(AccountEvent::forPerson($sam)[0])->toMatchArray(['text' => 'Changed your profile picture', 'by' => 'ServerOS Support', 'ip' => null])
        ->and($event->staff_name)->toBe('Boss')
        ->and($event->impersonation_session_id)->not->toBeNull();
    $copy = AdminAction::query()->where('action', 'viewas.write')->sole();
    expect($copy->meta)->toMatchArray(['action' => 'account.avatar.changed', 'what' => 'changed their profile picture']);
});

test('signed-in devices leave out staff viewing as you, and sign out one or all the others', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);
    storedSession($user, str_repeat('a', 40), MAC_CHROME);
    storedSession($user, str_repeat('b', 40), IPHONE_SAFARI);
    storedSession($user, str_repeat('c', 40), MAC_CHROME, ['impersonator_id' => 1]);
    // One whose payload can't be read (encrypted, say) is left out too, in case it's staff's.
    DB::table('sessions')->insert(['id' => str_repeat('d', 40), 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => MAC_CHROME, 'payload' => base64_encode('eyJiv...not json'), 'last_activity' => now()->timestamp]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))
        ->assertInertia(fn ($page) => $page->has('devices', 2)
            ->where('devices.0.device', 'Chrome on macOS')->where('devices.1.device', 'Safari on iOS')
            ->where('devices.0.current', false));
    $key = substr(hash_hmac('sha256', str_repeat('b', 40), (string) config('app.key')), 0, 32);

    $this->actingAs($user)->delete('/settings/sessions/'.$key);
    expect(DB::table('sessions')->pluck('id')->all())->toEqualCanonicalizing([str_repeat('a', 40), str_repeat('c', 40), str_repeat('d', 40)])
        ->and($user->fresh()->remember_token)->not->toBe('old-token');

    $this->actingAs($user)->delete('/settings/sessions');
    expect(DB::table('sessions')->count())->toBe(0)
        ->and(accountLines($user))->toBe(['Signed out Safari on iOS', 'Signed out every other device']);
});

test('staff viewing as someone can’t see or sign out their devices', function () {
    $sam = User::factory()->create();
    storedSession($sam, str_repeat('a', 40), MAC_CHROME);
    $this->actingAs(securityAdmin())->post(viewAsUrl($sam), ['reason' => 'Helping']);

    $this->delete('/settings/sessions')->assertForbidden();
    $this->delete('/settings/connections/github')->assertForbidden();

    expect(DB::table('sessions')->count())->toBe(1);
});

test('sign-ins, failed ones and signing out are in what staff see of someone', function () {
    $sam = User::factory()->create(['email' => 'sam@example.com']);
    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'wrong']);
    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password']);
    $this->post(route('logout'));

    // What the staff admin's user page and Audit › Sign-ins read.
    $staff = AccountEvent::forStaff($sam);
    expect($staff[0]['text'])->toBe('Signed out')
        ->and($staff[2]['text'])->toBe('Sign-in failed: wrong password')
        ->and(AccountEvent::query()->where('event', 'signin.failed')->sole()->email)->toBe('sam@example.com');
});

test('a year is kept, and failed sign-ins for 90 days', function () {
    $user = User::factory()->create();
    $at = fn (string $event, int $days) => AccountEvent::query()->create(['user_id' => $user->id, 'event' => $event, 'created_at' => now()->subDays($days)]);
    $at('signin', 300);
    $at('signin', 400);
    $at('signin.failed', 60);
    $at('signin.failed', 100);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(AccountEvent::query()->orderBy('id')->pluck('event')->all())->toBe(['signin', 'signin.failed']);
});

test('every Fortify and passkey route that changes something says what it records', function () {
    $vendor = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r) => array_diff($r->methods(), ['GET', 'HEAD']) !== [] && ! str_starts_with((string) $r->getActionName(), 'App\\') && $r->getName() !== null)
        ->filter(fn (Route $r) => str_starts_with((string) $r->getActionName(), 'Laravel\\Fortify\\') || str_starts_with((string) $r->getActionName(), 'Laravel\\Passkeys\\'))
        ->map(fn (Route $r) => $r->getName())
        ->values()->all();

    expect(array_diff($vendor, array_keys(RecordAccountEvents::VENDOR_ROUTES)))->toBe([])
        ->and($vendor)->not->toBeEmpty();
});
