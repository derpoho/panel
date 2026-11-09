<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('pages share the workspaces the user belongs to', function () {
    $user = User::factory()->create();
    $northwind = Workspace::factory()->create(['owner_id' => $user->id, 'name' => 'Northwind', 'slug' => 'northwind']);
    $acme = Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $acme->members()->attach($user, ['role' => 'member']);
    Workspace::factory()->create(['name' => 'Someone else', 'slug' => 'elsewhere']);

    $this->actingAs($user)
        ->get('https://'.$northwind->host().'/')
        ->assertInertia(fn (Assert $page) => $page
            ->has('workspaces', 2)
            ->where('workspaces.0', [
                'id' => $acme->id,
                'name' => 'Acme',
                'slug' => 'acme',
                'host' => $acme->host(),
                'url' => $acme->url(),
                'switchUrl' => 'https://acme.'.config('app.domain').'/switch',
                'logoUrl' => null,
                'pinnedAt' => null,
            ])
            ->where('workspaces.1.slug', 'northwind'));
});

test('guests get no workspaces', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('workspaces', []));
});

test('users can create another workspace and are taken to it', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('workspaces.store'), ['name' => 'Side Project', 'workspace' => 'Side-Project']);

    $workspace = Workspace::firstWhere('slug', 'side-project');

    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $workspace->url())
        ->assertSessionHas('inertia.flash_data.toast.message', 'Created Side Project');

    expect($workspace->name)->toBe('Side Project')
        ->and($workspace->hasMember($user))->toBeTrue()
        ->and($workspace->onTrial())->toBeTrue();
});

test('the slug is generated from the workspace name when left blank', function () {
    $user = User::factory()->create(['name' => 'Alex Rivera']);

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'Client Sites']);

    expect(Workspace::where('slug', 'client-sites')->exists())->toBeTrue();
});

test('taken slugs are rejected with suggestions when creating a workspace', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => 'Northwind', 'workspace' => 'northwind'])
        ->assertSessionHasErrors(['workspace' => 'That workspace name is taken. Try northwind-hq or northwind-team.']);
});

test('a workspace name is required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('switching lands on the workspace home with a toast', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $this->actingAs($workspace->owner)
        ->get('https://acme.'.config('app.domain').'/switch')
        ->assertRedirect($workspace->url());

    $this->actingAs($workspace->owner)
        ->get($workspace->url())
        ->assertInertia(fn (Assert $page) => $page->hasFlash('toast.message', 'Switched to Acme'));
});

test('you can only switch into workspaces you belong to', function () {
    Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs(User::factory()->create())
        ->get('https://acme.'.config('app.domain').'/switch')
        ->assertForbidden();
});
