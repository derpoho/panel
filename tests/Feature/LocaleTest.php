<?php

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('the browser’s language is used, if ServerOS speaks it', function () {
    $this->get('/login', ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de')->where('locales.es', 'Español'));

    $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('choosing a language beats the browser’s, and is kept on the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/locale', ['locale' => 'es'], ['Accept-Language' => 'de'])
        ->assertRedirect()
        ->assertCookie(SetLocale::COOKIE, 'es', encrypted: false);
    expect($user->fresh()->locale)->toBe('es');

    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user->fresh())
        ->get('https://'.$workspace->host().'/', ['Accept-Language' => 'de'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'es'));
});

test('a visitor’s choice is kept in a cookie', function () {
    $this->withUnencryptedCookie(SetLocale::COOKIE, 'de')
        ->get('/login', ['Accept-Language' => 'es'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
});

test('only languages ServerOS speaks can be chosen', function () {
    $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

test('the server’s own messages come in the chosen language', function () {
    // JSON requests only carry cookies in tests when asked to.
    $this->withCredentials()
        ->withUnencryptedCookie(SetLocale::COOKIE, 'es')
        ->postJson('/locale', ['locale' => ''])
        ->assertJsonPath('errors.locale.0', fn (string $message) => str_contains($message, 'obligatorio'));
});

test('Hindi is offered, and its messages come in Hindi', function () {
    $this->get('/login', ['Accept-Language' => 'hi-IN,hi;q=0.9,en;q=0.8'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'hi')->where('locales.hi', 'हिन्दी'));

    $this->withCredentials()
        ->withUnencryptedCookie(SetLocale::COOKIE, 'hi')
        ->postJson('/locale', ['locale' => ''])
        ->assertJsonPath('errors.locale.0', fn (string $message) => str_contains($message, 'आवश्यक'));
});

test('every language has the same strings as the others', function () {
    $keys = fn (string $code) => array_keys(json_decode((string) file_get_contents(lang_path("{$code}.json")), true));
    $spanish = $keys('es');

    foreach (array_keys(config('app.locales')) as $code) {
        if ($code !== 'en') {
            expect($keys($code))->toEqualCanonicalizing($spanish);
        }
    }
});

test('signing in takes the language picked while signed out', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->withUnencryptedCookie('locale', 'de')->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->locale)->toBe('de');
});
