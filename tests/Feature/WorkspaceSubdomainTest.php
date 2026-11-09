<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('members can view their workspace home on its subdomain', function () {
    $workspace = Workspace::factory()->create(['name' => 'Northwind', 'slug' => 'northwind']);

    $this->actingAs($workspace->owner)
        ->get('https://northwind.'.config('app.domain').'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Home')
            ->where('workspace.slug', 'northwind')
            ->where('workspace.name', 'Northwind')
            ->where('appUrl', rtrim(config('app.url'), '/')));
});

test('users cannot view a workspace they do not belong to', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs(User::factory()->create())
        ->get('https://northwind.'.config('app.domain').'/')
        ->assertForbidden();
});

test('invited members can view the workspace', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->get('https://northwind.'.config('app.domain').'/')
        ->assertOk();
});

test('unknown workspaces return not found', function () {
    $this->actingAs(User::factory()->create())
        ->get('https://nope.'.config('app.domain').'/')
        ->assertNotFound();
});

test('guests are sent to the login page on the root domain', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->get('https://northwind.'.config('app.domain').'/')
        ->assertRedirect(rtrim(config('app.url'), '/').'/login');
});

test('the root domain still serves the front page', function () {
    // The website on serveros.com (its package tests it); signing up on a panel without one.
    $this->get(route('home'));
    expect(request()->route()?->getName())->toBe('home');

    $this->followingRedirects()->get(route('home'))->assertOk();
});

test('signing in sends inertia requests to the workspace with a full page visit', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $this->withSession(['url.intended' => $workspace->url()]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('login.store'), [
            'email' => $workspace->owner->email,
            'password' => 'password',
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $workspace->url());
});
