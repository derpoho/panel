<?php

use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;

function onHost(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

test('a Business workspace can make a role and give it to someone', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)->post(onHost($workspace, '/settings/roles'), [
        'name' => 'Billing manager',
        'permissions' => ['manage-billing'],
    ])->assertRedirect();

    $role = WorkspaceCustomRole::query()->sole();

    $this->actingAs($workspace->owner)->patch(onHost($workspace, "/settings/members/{$sam->id}"), [
        'role' => "custom:{$role->id}",
    ])->assertRedirect();

    expect($workspace->fresh()->allows($sam, WorkspacePermission::ManageBilling))->toBeTrue()
        ->and($workspace->fresh()->allows($sam, WorkspacePermission::ManageMachines))->toBeFalse();
});

test('custom roles need Business, and fall back to the base role off it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->post(onHost($workspace, '/settings/roles'), [
        'name' => 'Ops', 'permissions' => ['manage-machines'],
    ])->assertForbidden();

    $role = $workspace->customRoles()->create(['name' => 'Ops', 'permissions' => ['manage-machines']]);
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'viewer', 'custom_role_id' => $role->id]);

    expect($workspace->allows($sam, WorkspacePermission::ManageMachines))->toBeFalse();
});

test('a role from another workspace cannot be given out', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $other = Workspace::factory()->onPlan(Plan::Business)->create();
    $role = $other->customRoles()->create(['name' => 'Ops', 'permissions' => ['manage-machines']]);
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)
        ->patch(onHost($workspace, "/settings/members/{$sam->id}"), ['role' => "custom:{$role->id}"])
        ->assertSessionHasErrors('role');
});

test('requiring two-factor sends members without it to the security tab', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'admin']);

    $this->actingAs($owner)->put(onHost($workspace, '/settings/two-factor'), ['require' => true])->assertRedirect();
    expect($workspace->fresh()->require_two_factor)->toBeTrue();

    $this->actingAs($sam)->get(onHost($workspace, '/machines'))
        ->assertRedirect(route('workspace.settings.security', $workspace->slug));
    $this->actingAs($owner)->get(onHost($workspace, '/machines'))->assertOk();
});

test('you cannot require two-factor without having it yourself', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->put(onHost($workspace, '/settings/two-factor'), ['require' => true])
        ->assertSessionHasErrors('require');

    expect($workspace->fresh()->require_two_factor)->toBeFalse();
});

test('admins can export the activity log as CSV, viewers cannot', function () {
    $workspace = Workspace::factory()->create();
    Activity::record($workspace, 'member.invite', 'new@example.com', user: $workspace->owner, meta: ['role' => 'admin']);
    // A name or command that a spreadsheet would run as a formula is kept as text.
    $workspace->owner->forceFill(['name' => '=SUM(1)'])->save();
    Activity::record($workspace, 'console.command', 'umami', user: $workspace->owner, meta: ['command' => '+cmd|calc']);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $response = $this->actingAs($workspace->owner)->get(onHost($workspace, '/activity/export'))->assertOk();
    expect($response->streamedContent())
        ->toContain('"Time (UTC)",Who,Email,"By ServerOS Support",Source,"MCP client",Action,"What happened",Machine,Details,"IP address","User agent","Request ID"')
        ->toContain('member.invite')
        ->toContain(",'=SUM(1),")->toContain(",'+cmd|calc,")
        ->not->toContain(',=SUM(1),')->not->toContain(',+cmd|calc,');

    $this->actingAs($viewer)->get(onHost($workspace, '/activity/export'))->assertForbidden();
});
