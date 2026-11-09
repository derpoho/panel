<?php

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Inertia\Testing\AssertableInertia as Assert;

function membersUrl(Workspace $workspace, string $suffix = ''): string
{
    return 'https://'.$workspace->host().'/settings/members'.$suffix;
}

function joinAs(Workspace $workspace, string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

test('the members page lists members by role, then invitations', function () {
    $workspace = Workspace::factory()->create();
    joinAs($workspace, 'viewer', ['name' => 'Vera']);
    joinAs($workspace, 'admin', ['name' => 'Ada']);
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'new@example.com']);

    $this->actingAs($workspace->owner)
        ->get(membersUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/Members')
            ->where('canManageMembers', true)
            ->where('hasStaffRoles', true)
            ->has('roles', 4)
            ->has('members', 3)
            ->where('members.0.role', 'owner')
            ->where('members.0.isYou', true)
            ->where('members.1.name', 'Ada')
            ->where('members.2.name', 'Vera')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'new@example.com'));
});

test('admins can change a member role', function () {
    $workspace = Workspace::factory()->create();
    $admin = joinAs($workspace, 'admin');
    $member = joinAs($workspace, 'member');

    $this->actingAs($admin)
        ->patch(membersUrl($workspace, "/{$member->id}"), ['role' => 'viewer'])
        ->assertSessionHasNoErrors();

    expect($workspace->roleOf($member)->value)->toBe('viewer');
});

test('the owner role cannot be changed and nobody can change their own role', function () {
    $workspace = Workspace::factory()->create();
    $admin = joinAs($workspace, 'admin');

    $this->actingAs($admin)
        ->patch(membersUrl($workspace, "/{$workspace->owner_id}"), ['role' => 'viewer'])
        ->assertSessionHasErrors(['role' => 'The owner’s role can’t be changed.']);

    $this->actingAs($admin)
        ->patch(membersUrl($workspace, "/{$admin->id}"), ['role' => 'viewer'])
        ->assertSessionHasErrors(['role' => 'You can’t change your own role.']);
});

test('roles cannot be set to owner', function () {
    $workspace = Workspace::factory()->create();
    $member = joinAs($workspace, 'member');

    $this->actingAs($workspace->owner)
        ->patch(membersUrl($workspace, "/{$member->id}"), ['role' => 'owner'])
        ->assertSessionHasErrors('role');
});

test('members cannot manage other members', function () {
    $workspace = Workspace::factory()->create();
    $member = joinAs($workspace, 'member');
    $other = joinAs($workspace, 'viewer');

    $this->actingAs($member)
        ->patch(membersUrl($workspace, "/{$other->id}"), ['role' => 'admin'])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(membersUrl($workspace, "/{$other->id}"))
        ->assertForbidden();
});

test('admins can remove members but not the owner', function () {
    $workspace = Workspace::factory()->create();
    $admin = joinAs($workspace, 'admin');
    $member = joinAs($workspace, 'member');

    $this->actingAs($admin)->delete(membersUrl($workspace, "/{$member->id}"))->assertSessionHasNoErrors();
    expect($workspace->hasMember($member))->toBeFalse();

    $this->actingAs($admin)
        ->delete(membersUrl($workspace, "/{$workspace->owner_id}"))
        ->assertSessionHasErrors(['member' => 'The owner can’t be removed.']);
});

test('members can leave, owners cannot', function () {
    $workspace = Workspace::factory()->create();
    $viewer = joinAs($workspace, 'viewer');

    $this->actingAs($viewer)
        ->delete(membersUrl($workspace, "/{$viewer->id}"))
        ->assertRedirect(rtrim(config('app.url'), '/').'/dashboard');
    expect($workspace->hasMember($viewer))->toBeFalse();

    $this->actingAs($workspace->owner)
        ->delete(membersUrl($workspace, "/{$workspace->owner_id}"))
        ->assertSessionHasErrors('member');
});

test('people outside the workspace are not found', function () {
    $workspace = Workspace::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($workspace->owner)
        ->patch(membersUrl($workspace, "/{$stranger->id}"), ['role' => 'admin'])
        ->assertNotFound();
});

test('starter workspaces cannot use staff roles', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();
    $admin = joinAs($workspace, 'admin');

    $this->actingAs($workspace->owner)
        ->patch(membersUrl($workspace, "/{$admin->id}"), ['role' => 'member'])
        ->assertSessionHasErrors(['role' => 'Member and Viewer roles are on Pro and Business.']);
});
