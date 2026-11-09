<?php

use App\Http\Middleware\AllowWorkspacePasskeys;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

test('passkeys work on a workspace address, not on other domains', function () {
    config()->set('app.domain', 'serveros.test');
    config()->set('passkeys.allowed_origins', ['https://serveros.test']);
    $pass = fn () => new Response;

    (new AllowWorkspacePasskeys)->handle(Request::create('https://acme.serveros.test/settings/security'), $pass);
    expect(config('passkeys.allowed_origins'))->toBe(['https://serveros.test', 'https://acme.serveros.test']);

    config()->set('passkeys.allowed_origins', ['https://serveros.test']);
    (new AllowWorkspacePasskeys)->handle(Request::create('https://serveros.test.evil.com/'), $pass);
    (new AllowWorkspacePasskeys)->handle(Request::create('https://evilserveros.test/'), $pass);
    expect(config('passkeys.allowed_origins'))->toBe(['https://serveros.test']);
});

test('a workspace page allows its own address for passkeys', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/')->assertOk();

    expect(config('passkeys.allowed_origins'))->toContain('https://'.$workspace->host());
});
