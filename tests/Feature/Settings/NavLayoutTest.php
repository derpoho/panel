<?php

use App\Models\User;
use App\Models\Workspace;

test('the dashboard navigation can be switched to a sidebar and back', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->put('/settings/appearance/navigation', ['layout' => 'sidebar'])
        ->assertRedirect('/settings/appearance');

    expect($user->refresh()->nav_layout)->toBe('sidebar');

    $this->actingAs($user)
        ->put('/settings/appearance/navigation', ['layout' => 'top'])
        ->assertRedirect();

    expect($user->refresh()->nav_layout)->toBeNull();
});

test('only the known layouts are accepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/appearance/navigation', ['layout' => 'bottom'])
        ->assertSessionHasErrors('layout');

    expect($user->refresh()->nav_layout)->toBeNull();
});

test('guests cannot change it', function () {
    $this->put('/settings/appearance/navigation', ['layout' => 'sidebar'])
        ->assertRedirect();
});

test('the dashboard settings have an Appearance page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->get($workspace->url().'/settings/appearance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/settings/Appearance'));
});
