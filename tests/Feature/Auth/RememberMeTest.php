<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkey;
use Symfony\Component\HttpFoundation\Cookie;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/** The remember cookie a response set, if any. */
function rememberCookie($response): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
}

/** A new browser session, with nothing but this remember cookie. */
function comeBackWith(Cookie $cookie)
{
    Auth::forgetGuards();
    test()->flushSession();

    return test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/settings/profile');
}

test('ticking Remember me keeps you signed in after the session ends', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $cookie = rememberCookie($response);
    expect($cookie)->not->toBeNull();

    comeBackWith($cookie)->assertOk();
    expect(Auth::id())->toBe($user->id)
        ->and(Auth::viaRemember())->toBeTrue();
});

test('without Remember me there is no remember cookie', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect(rememberCookie($response))->toBeNull();
});

test('signing out on one device leaves your other remembered devices signed in', function () {
    $user = User::factory()->create();

    // The phone: signed in and remembered.
    $phone = rememberCookie($this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']));

    // The laptop: signed in, then signed out.
    Auth::forgetGuards();
    $this->flushSession();
    $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);
    $this->post('/logout');
    $this->assertGuest();

    // The phone's session runs out, and it comes back on its cookie.
    comeBackWith($phone)->assertOk();
    expect(Auth::id())->toBe($user->id);
});

test('signing someone out everywhere still ends their remembered devices', function () {
    $user = User::factory()->create();
    $phone = rememberCookie($this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']));

    $user->fresh()->signOutEverywhere();

    comeBackWith($phone)->assertRedirect();
    $this->assertGuest();
});

test('the sign-in page asks for passkey sign-ins to be remembered, and they are', function () {
    $user = User::factory()->create();
    $passkey = Passkey::query()->forceCreate(['user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'abc', 'credential' => []]);

    // The browser's half of WebAuthn can't run here: take the passkey as checked.
    app()->bind(VerifyPasskey::class, fn () => new class($passkey) extends VerifyPasskey
    {
        public function __construct(private Passkey $passkey) {}

        public function __invoke($credential, $options, $user = null): Passkey
        {
            return $this->passkey;
        }
    });
    app()->bind(PasskeyVerificationRequest::class, fn () => new class extends PasskeyVerificationRequest
    {
        public function rules(): array
        {
            return ['remember' => ['boolean']];
        }

        protected function passedValidation(): void {}

        public function credential(): PublicKeyCredential
        {
            return Mockery::mock(PublicKeyCredential::class);
        }

        public function verificationOptions(): PublicKeyCredentialRequestOptions
        {
            return PublicKeyCredentialRequestOptions::create('challenge');
        }
    });

    // The address Login.vue posts the passkey to.
    $response = $this->postJson('/passkeys/login?remember=1', []);

    $response->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(rememberCookie($response))->not->toBeNull();
});
