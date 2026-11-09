<?php

use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\McpToken;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Activity\ActivityLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function auditHost(Workspace $workspace): string
{
    return 'https://'.$workspace->host();
}

/** @return list<string> each entry as the Activity page reads it */
function activitySentences(Workspace $workspace): array
{
    return Activity::query()->where('workspace_id', $workspace->id)->orderBy('id')->get()
        ->map(fn (Activity $a) => ActivityLine::sentence($a))->all();
}

/** Boss, an admin, viewing as the workspace's owner. */
function viewAsOwner(Workspace $workspace): User
{
    $boss = User::factory()->admin()->create(['name' => 'Boss']);
    test()->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Helping out']);

    return $boss;
}

test('renaming the workspace, its logo, and deleting it are recorded', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $owner = $workspace->owner;

    $this->actingAs($owner)->patch(auditHost($workspace).'/settings/general', ['name' => 'Acme'])->assertRedirect();
    $this->actingAs($owner)->patch(auditHost($workspace).'/settings/general', ['name' => 'Acme Ltd'])->assertRedirect();
    $this->actingAs($owner)->post(auditHost($workspace).'/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertRedirect();
    $this->actingAs($owner)->delete(auditHost($workspace).'/settings/logo')->assertRedirect();
    $this->actingAs($owner)->delete(auditHost($workspace).'/settings/logo')->assertRedirect();

    // Saving the same name, or removing a logo that's gone, says nothing.
    expect(activitySentences($workspace))->toBe([
        'Sam renamed the workspace to Acme Ltd (was Acme)',
        'Sam changed the workspace logo',
        'Sam removed the workspace logo',
    ]);
});

test('a workspace deleted while viewing as its owner stays in the admin log', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    viewAsOwner($workspace);

    $this->delete(auditHost($workspace).'/settings/workspace', ['confirmation' => 'acme']);

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
    $staff = AdminAction::query()->where('action', 'viewas.write')->sole();
    expect($staff->meta['action'])->toBe('workspace.delete')
        ->and($staff->target_label)->toBe('Acme');
});

test('machine and service groups say who made, changed and removed them', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $owner = $workspace->owner;
    [$a, $b] = Machine::factory()->count(2)->for($workspace)->create();
    $a->forceFill(['name' => 'web-1'])->save();

    $this->actingAs($owner)->post(auditHost($workspace).'/machine-groups', ['name' => 'Web', 'machines' => [$a->id]]);
    $group = MachineGroup::query()->sole();
    $this->actingAs($owner)->put(auditHost($workspace)."/machine-groups/{$group->id}", ['name' => 'Web', 'machines' => [$a->id]]);
    $this->actingAs($owner)->put(auditHost($workspace)."/machine-groups/{$group->id}", ['name' => 'Web', 'machines' => [$a->id, $b->id]]);
    $this->actingAs($owner)->put(auditHost($workspace)."/machine-groups/{$group->id}", ['name' => 'Frontend', 'machines' => [$a->id, $b->id]]);
    $this->actingAs($owner)->delete(auditHost($workspace)."/machine-groups/{$group->id}");

    $services = auditHost($workspace)."/machines/{$a->id}/service-groups";
    $this->actingAs($owner)->post($services, ['name' => 'Data', 'services' => ['docker:aaa']]);
    $data = ServiceGroup::query()->sole();
    $this->actingAs($owner)->put("{$services}/{$data->id}", ['name' => 'Data', 'services' => ['docker:aaa']]);
    $this->actingAs($owner)->put("{$services}/{$data->id}", ['name' => 'Databases', 'services' => ['docker:aaa']]);
    $this->actingAs($owner)->delete("{$services}/{$data->id}");

    expect(activitySentences($workspace))->toBe([
        'Sam created the machine group Web',
        'Sam changed which machines are in the group Web',
        'Sam renamed a machine group to Frontend (was Web)',
        'Sam deleted the machine group Frontend',
        'Sam created the service group Data on web-1',
        'Sam renamed a service group to Databases (was Data) on web-1',
        'Sam deleted the service group Databases on web-1',
    ]);
});

test('accepting an invitation says who joined, and as what', function () {
    $workspace = Workspace::factory()->create();
    $invitee = User::factory()->create(['name' => 'Priya', 'email' => 'priya@example.com']);
    WorkspaceInvitation::factory()->for($workspace)->withToken('join-token')->create(['email' => 'priya@example.com', 'role' => 'admin']);

    $this->actingAs($invitee)->post(route('invitations.accept', 'join-token'));

    $entry = Activity::query()->where('action', 'member.join')->sole();
    expect($entry->user_id)->toBe($invitee->id)
        ->and(ActivityLine::sentence($entry))->toBe('Priya joined the workspace as Admin');
});

test('editing a backup destination is recorded only when something changed', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $machine = Machine::factory()->for($workspace)->create();
    $destination = new BackupDestination(['name' => 'Vault', 'type' => 'machine', 'machine_id' => $machine->id]);
    $destination->workspace_id = $workspace->id;
    $destination->save();

    $url = auditHost($workspace)."/backup-destinations/{$destination->id}";
    $this->actingAs($workspace->owner)->put($url, ['name' => 'Vault'])->assertRedirect();
    $this->actingAs($workspace->owner)->put($url, ['name' => 'Big vault'])->assertRedirect();

    expect(activitySentences($workspace))->toBe(['Sam changed the backup destination Big vault (was Vault)']);
});

test('what staff change while viewing as someone that records nothing itself is still noted, for us only', function () {
    $workspace = Workspace::factory()->create();
    viewAsOwner($workspace);

    $this->put(auditHost($workspace).'/settings/notifications', ['alert_emails' => 'none', 'weekly_summary' => false])->assertRedirect();

    expect(Activity::query()->count())->toBe(0);
    $staff = AdminAction::query()->where('action', 'viewas.write')->sole();
    expect($staff->meta)->toMatchArray(['action' => 'workspace.settings.notifications', 'what' => 'changed Settings › Notifications', 'staff_only' => true])
        ->and($staff->target_id)->toBe($workspace->id)
        ->and($staff->impersonation_session_id)->not->toBeNull();
});

test('a write that records itself is not noted twice, and a refused one not at all', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    viewAsOwner($workspace);

    $this->patch(auditHost($workspace).'/settings/general', ['name' => 'Acme Ltd'])->assertRedirect();
    $this->patch(auditHost($workspace).'/settings/general', ['name' => ''])->assertSessionHasErrors('name');

    expect(AdminAction::query()->where('action', 'viewas.write')->pluck('meta')->pluck('action')->all())->toBe(['workspace.rename']);
});

test('MCP keys and the MCP terms are off limits while viewing as someone', function () {
    $workspace = Workspace::factory()->create();
    viewAsOwner($workspace);

    // A key or an approved MCP client would go on acting as them, credited
    // to them, long after the View as ended; the terms are theirs to accept.
    $this->postJson(auditHost($workspace).'/settings/mcp/keys', ['name' => 'Laptop'])->assertForbidden();
    $this->postJson('/settings/mcp-terms')->assertForbidden();
    $this->post('/oauth/authorize', ['approve' => '1'])->assertForbidden();
    expect(McpToken::query()->count())->toBe(0)
        ->and($workspace->owner->fresh()->mcp_terms_accepted_at)->toBeNull();
});

test('an API key, or a hand-off to their own domain, is off limits while viewing as someone', function () {
    $workspace = Workspace::factory()->create();
    viewAsOwner($workspace);

    // A key would act as them, credited to them, long after the View as ended.
    $this->postJson(auditHost($workspace).'/settings/api/keys', ['name' => 'Deploy bot'])
        ->assertForbidden()
        ->assertJsonPath('message', 'You’re viewing as this person, so keys and apps that act as them are off limits.');
    // Their own domain would sign this browser in as them there, with no View as to show for it.
    $this->get(auditHost($workspace).'/domains/go?to=/machines')->assertForbidden();
    expect(McpToken::query()->count())->toBe(0);
});

test('a Google or GitHub connect the admin started for themselves never lands on the person', function () {
    $workspace = Workspace::factory()->create();
    $boss = User::factory()->admin()->create(['name' => 'Boss']);

    // Boss is halfway through connecting their own GitHub when they start.
    $this->actingAs($boss)
        ->withSession(['oauth.connect' => ['provider' => 'github', 'return' => '/settings/profile']])
        ->post(viewAsUrl($workspace->owner), ['reason' => 'Helping out']);

    $this->assertAuthenticatedAs($workspace->owner);
    expect(session()->has('oauth.connect'))->toBeFalse();
    $this->get('/auth/github/callback?code=abc&state=xyz')->assertForbidden();
    expect($workspace->owner->socialAccounts()->count())->toBe(0);
});

test('the workspace’s GitHub connection is off limits while viewing as someone', function () {
    $workspace = Workspace::factory()->create();
    $installation = $workspace->githubInstallations()->create(['installation_id' => 1, 'account_login' => 'acme', 'connected_by' => $workspace->owner->id]);
    viewAsOwner($workspace);

    $this->get(auditHost($workspace).'/settings/github/connect')->assertForbidden();
    $this->get('/github/setup?installation_id=2&setup_action=install')->assertForbidden();
    $this->deleteJson(auditHost($workspace).'/settings/github/'.$installation->id)
        ->assertForbidden()
        ->assertJsonPath('message', 'You’re viewing as this person, so their GitHub connection is off limits.');
    expect($workspace->githubInstallations()->count())->toBe(1);
});

test('what staff add or remove while viewing as someone reads as added or removed', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->for($workspace->owner, 'owner')->create();
    viewAsOwner($workspace);

    $this->post(auditHost($workspace)."/pins/{$other->slug}")->assertRedirect();
    $this->delete(auditHost($workspace)."/pins/{$other->slug}")->assertRedirect();

    expect(AdminAction::query()->where('action', 'viewas.write')->pluck('meta')->map(fn (array $m) => $m['what'])->all())
        ->toBe(['added Pins', 'removed Pins']);
});

test('creating a workspace is the first line of its activity', function () {
    $owner = User::factory()->create(['name' => 'Sam']);
    Workspace::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->post('/workspaces', ['name' => 'Client B'])->assertRedirect();

    $workspace = Workspace::query()->where('name', 'Client B')->sole();
    expect(activitySentences($workspace))->toBe(['Sam created the workspace Client B']);
    $entry = Activity::query()->where('workspace_id', $workspace->id)->sole();
    expect($entry->user_id)->toBe($owner->id)->and($entry->ip)->toBe('127.0.0.1');
});
