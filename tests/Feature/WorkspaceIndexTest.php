<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('the workspaces page lists only your workspaces with counts and your role', function () {
    $user = User::factory()->create();
    $mine = Workspace::factory()->create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme']);
    Machine::factory()->for($mine)->count(2)->create();
    $shared = Workspace::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
    $shared->members()->attach($user, ['role' => 'viewer']);
    Workspace::factory()->create(['name' => 'Not mine']);

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/Index')
            ->has('workspaces', 2)
            ->where('workspaces.0.name', 'Acme')
            ->where('workspaces.0.role', 'Owner')
            ->where('workspaces.0.machineCount', 2)
            ->where('workspaces.0.memberCount', 1)
            ->where('workspaces.0.switchUrl', route('workspace.switch', ['workspace' => 'acme']))
            ->where('workspaces.1.name', 'Beta')
            ->where('workspaces.1.role', 'Viewer'));
});

test('guests cannot see the workspaces page', function () {
    $this->get(route('workspaces.index'))->assertRedirect();
});
