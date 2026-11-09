<?php

use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Mcp\Scopes;
use App\Mcp\Tools\TeamTools;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use App\Support\Monitors\HostResolver;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => config()->set('mcp.groups', [TeamTools::class]));

const TEAM_TOOLS_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function teamToolsJoin(Workspace $workspace, string $role, array $attributes = [], ?WorkspaceCustomRole $custom = null): User
{
    $user = User::factory()->create($attributes);
    $workspace->members()->attach($user, ['role' => $role, 'custom_role_id' => $custom?->id]);

    return $user;
}

/** The last activity entry, with its meta. */
function teamToolsActivity(Workspace $workspace): ?Activity
{
    return Activity::query()->where('workspace_id', $workspace->id)->latest('id')->first();
}

/** Image downloads that work for cdn.example.com and refuse internal hosts. */
function teamToolsImages(): void
{
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return str_contains($host, 'internal') ? [] : ['93.184.215.14'];
        }
    });
    Http::fake([
        'https://cdn.example.com/logo.png' => Http::response(base64_decode(TEAM_TOOLS_PNG), 200, ['Content-Type' => 'image/png']),
        'https://cdn.example.com/logo.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 200, ['Content-Type' => 'image/svg+xml']),
    ]);
}

test('tools are listed by what the connection and role allow', function () {
    $workspace = Workspace::factory()->create();
    $names = fn (string $key) => collect(mcpRequest($workspace, $key, 'tools/list')->json('result.tools'))->pluck('name')->all();

    $all = ['list_members', 'list_roles', 'invite_member', 'cancel_invitation', 'change_member_role', 'remove_member', 'update_workspace', 'get_my_notifications', 'update_my_notifications'];
    expect($names(mcpKey($workspace)))->toEqualCanonicalizing($all)
        ->and($names(mcpKey($workspace, teamToolsJoin($workspace, 'admin'))))->toEqualCanonicalizing($all)
        ->and($names(mcpKey($workspace, teamToolsJoin($workspace, 'member'))))->toEqualCanonicalizing(['list_members', 'list_roles', 'get_my_notifications', 'update_my_notifications'])
        ->and($names(mcpKey($workspace, teamToolsJoin($workspace, 'viewer'))))->toEqualCanonicalizing(['list_members', 'list_roles', 'get_my_notifications', 'update_my_notifications'])
        ->and($names(mcpKey($workspace, scopes: [Scopes::READ])))->toEqualCanonicalizing(['list_members', 'list_roles', 'get_my_notifications']);

    $tools = collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->keyBy('name');
    expect($tools['remove_member']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['remove_member']['inputSchema']['required'])->toBe(['member', 'confirm'])
        ->and($tools['list_members']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools['invite_member']['annotations']['readOnlyHint'])->toBeFalse()
        ->and($tools['invite_member']['annotations']['idempotentHint'])->toBeFalse()
        ->and($tools['update_workspace']['annotations']['openWorldHint'])->toBeTrue()
        ->and($tools['update_workspace']['inputSchema']['properties'])->not->toHaveKey('slug');
});

test('a custom role that grants managing members can use the member tools', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $role = $workspace->customRoles()->create(['name' => 'People ops', 'permissions' => [WorkspacePermission::ManageMembers->value]]);
    $sam = teamToolsJoin($workspace, 'viewer', custom: $role);
    $names = collect(mcpRequest($workspace, mcpKey($workspace, $sam), 'tools/list')->json('result.tools'))->pluck('name');

    expect($names)->toContain('invite_member', 'change_member_role', 'remove_member', 'cancel_invitation')
        ->not->toContain('update_workspace');

    // Off Business the custom role doesn't count: they're a Viewer.
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    expect(collect(mcpRequest($workspace, mcpKey($workspace, $sam), 'tools/list')->json('result.tools'))->pluck('name'))->not->toContain('invite_member');
});

test('list_members lists members by role, then invites, as the Members page does', function () {
    $owner = User::factory()->withTwoFactor()->create(['name' => 'Olive', 'email' => 'olive@example.com']);
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'Acme']);
    teamToolsJoin($workspace, 'viewer', ['name' => 'Vera', 'email' => 'vera@example.com']);
    teamToolsJoin($workspace, 'admin', ['name' => 'Ada', 'email' => 'ada@example.com']);
    teamToolsJoin($workspace, 'member', ['name' => 'Bea', 'email' => 'bea@example.com']);
    teamToolsJoin($workspace, 'admin', ['name' => 'Abe', 'email' => 'abe@example.com']);
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'new@example.com', 'role' => 'viewer', 'invited_by' => $owner->id]);
    WorkspaceInvitation::factory()->for($workspace)->expired()->create(['email' => 'old@example.com', 'role' => 'admin']);
    WorkspaceInvitation::factory()->create(['email' => 'elsewhere@example.com']);

    $result = mcpCall($workspace, mcpKey($workspace), 'list_members');
    expect($result['error'])->toBeFalse();
    $data = $result['data'];

    expect(collect($data['members'])->pluck('name')->all())->toBe(['Olive', 'Abe', 'Ada', 'Bea', 'Vera'])
        ->and($data['total'])->toBe(5)
        ->and($data['truncated'])->toBeFalse()
        ->and($data['canManageMembers'])->toBeTrue()
        ->and($data['url'])->toBe('https://'.$workspace->host().'/settings/members')
        ->and($data['members'][0])->toMatchArray([
            'name' => 'Olive', 'email' => 'olive@example.com', 'role' => 'owner', 'roleLabel' => 'Owner',
            'customRole' => null, 'twoFactor' => true, 'needsTwoFactor' => false, 'isYou' => true,
            'canChangeRole' => false, 'canRemove' => false,
        ])
        ->and($data['members'][0]['joinedAt'])->toBeString()
        ->and($data['members'][4])->toMatchArray(['name' => 'Vera', 'role' => 'viewer', 'roleLabel' => 'Viewer', 'twoFactor' => false, 'isYou' => false, 'canChangeRole' => true, 'canRemove' => true]);

    $invites = collect($data['invitations'])->keyBy('email');
    expect($invites->keys()->all())->toEqualCanonicalizing(['new@example.com', 'old@example.com'])
        ->and($invites['new@example.com'])->toMatchArray(['role' => 'viewer', 'roleLabel' => 'Viewer', 'invitedBy' => 'Olive', 'expired' => false])
        ->and($invites['old@example.com'])->toMatchArray(['role' => 'admin', 'roleLabel' => 'Admin', 'expired' => true])
        ->and($result['text'])->not->toContain('token');

    // Search and limit.
    $found = mcpCall($workspace, mcpKey($workspace), 'list_members', ['search' => 'AD'])['data'];
    expect(collect($found['members'])->pluck('name')->all())->toBe(['Ada'])
        ->and($found['invitations'])->toBe([]);
    $limited = mcpCall($workspace, mcpKey($workspace), 'list_members', ['limit' => 2])['data'];
    expect($limited['members'])->toHaveCount(2)->and($limited['total'])->toBe(5)->and($limited['truncated'])->toBeTrue();
    expect(mcpCall($workspace, mcpKey($workspace), 'list_members', ['limit' => 0])['text'])->toContain('Invalid arguments');
});

test('list_members shows two-factor as the Members page does when the workspace requires it', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->forceFill(['require_two_factor' => true])->save();
    teamToolsJoin($workspace, 'admin', ['name' => 'Ada']);
    teamToolsJoin($workspace, 'member', ['name' => 'Bea'])->forceFill(['two_factor_confirmed_at' => now()])->save();

    $data = mcpCall($workspace, mcpKey($workspace), 'list_members')['data'];
    $members = collect($data['members'])->keyBy('name');

    expect($data['requireTwoFactor'])->toBeTrue()
        ->and($data['withoutTwoFactor'])->toBe(1)
        ->and($members['Ada'])->toMatchArray(['twoFactor' => false, 'needsTwoFactor' => true])
        ->and($members['Bea'])->toMatchArray(['twoFactor' => true, 'needsTwoFactor' => false]);
});

test('list_members shows custom role names on Business, and the base role off it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $role = $workspace->customRoles()->create(['name' => 'Billing manager', 'permissions' => ['manage-billing']]);
    teamToolsJoin($workspace, 'viewer', ['name' => 'Sam'], $role);
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'kim@example.com', 'role' => 'viewer', 'custom_role_id' => $role->id]);

    $data = mcpCall($workspace, mcpKey($workspace), 'list_members')['data'];
    $sam = collect($data['members'])->firstWhere('name', 'Sam');
    expect($sam)->toMatchArray(['role' => 'viewer', 'roleLabel' => 'Billing manager', 'customRole' => ['id' => $role->id, 'name' => 'Billing manager']])
        ->and($data['invitations'][0])->toMatchArray(['roleLabel' => 'Billing manager', 'customRole' => ['id' => $role->id, 'name' => 'Billing manager']]);

    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $data = mcpCall($workspace, mcpKey($workspace), 'list_members')['data'];
    expect(collect($data['members'])->firstWhere('name', 'Sam'))->toMatchArray(['roleLabel' => 'Viewer', 'customRole' => null])
        ->and($data['invitations'][0]['roleLabel'])->toBe('Viewer');
});

test('viewers can list members but may not change anything', function () {
    $workspace = Workspace::factory()->create();
    $viewer = teamToolsJoin($workspace, 'viewer');
    $member = teamToolsJoin($workspace, 'member');
    $key = mcpKey($workspace, $viewer);

    $data = mcpCall($workspace, $key, 'list_members')['data'];
    expect($data['canManageMembers'])->toBeFalse()
        ->and(collect($data['members'])->every(fn (array $m) => ! $m['canChangeRole'] && ! $m['canRemove']))->toBeTrue();

    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $member->email, 'role' => 'admin'])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, $key, 'remove_member', ['member' => $member->email, 'confirm' => $member->email])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'viewer'])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['name' => 'Hijacked'])['text'])->toContain('doesn’t allow that')
        ->and($workspace->roleOf($member)->value)->toBe('member')
        ->and($workspace->fresh()->name)->not->toBe('Hijacked')
        ->and($workspace->invitations()->count())->toBe(0);
});

test('list_roles explains the built-in roles and what the plan offers', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    teamToolsJoin($workspace, 'admin');
    teamToolsJoin($workspace, 'viewer');
    teamToolsJoin($workspace, 'viewer');

    $data = mcpCall($workspace, mcpKey($workspace), 'list_roles')['data'];
    $roles = collect($data['roles'])->keyBy('value');

    expect($roles->keys()->all())->toBe(['owner', 'admin', 'member', 'viewer'])
        ->and($roles['owner'])->toMatchArray(['label' => 'Owner', 'description' => 'Full access, including billing.', 'assignable' => false, 'available' => true, 'members' => 1])
        ->and(collect($roles['owner']['permissions'])->pluck('value')->all())->toBe(['manage-billing', 'manage-settings', 'manage-members', 'manage-machines'])
        ->and(collect($roles['admin']['permissions'])->pluck('value')->all())->toBe(['manage-settings', 'manage-members', 'manage-machines'])
        ->and($roles['member']['permissions'])->toBe([['value' => 'manage-machines', 'label' => 'Connect and manage machines']])
        ->and($roles['viewer'])->toMatchArray(['description' => 'Read-only access.', 'permissions' => [], 'available' => true, 'members' => 2])
        ->and($data['hasStaffRoles'])->toBeTrue()
        ->and($data['hasCustomRoles'])->toBeFalse()
        ->and($data['customRoles'])->toBe([])
        ->and($data['you'])->toBe(['role' => 'owner', 'roleLabel' => 'Owner', 'canManageMembers' => true])
        ->and($data['permissions'])->toHaveCount(4);

    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    $data = mcpCall($essential, mcpKey($essential), 'list_roles')['data'];
    $roles = collect($data['roles'])->keyBy('value');
    expect($roles['admin']['available'])->toBeTrue()
        ->and($roles['member']['available'])->toBeFalse()
        ->and($roles['viewer']['available'])->toBeFalse()
        ->and($data['hasStaffRoles'])->toBeFalse()
        ->and(implode(' ', $data['notes']))->toContain('Member and Viewer roles are on Pro and Business');
});

test('list_roles lists the workspace’s own roles, and says when the plan no longer uses them', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $ops = $workspace->customRoles()->create(['name' => 'Ops', 'description' => 'Runs the servers', 'permissions' => ['manage-machines', 'manage-settings']]);
    $workspace->customRoles()->create(['name' => 'Auditor', 'permissions' => []]);
    teamToolsJoin($workspace, 'viewer', custom: $ops);
    teamToolsJoin($workspace, 'viewer', custom: $ops);
    teamToolsJoin($workspace, 'viewer');

    $data = mcpCall($workspace, mcpKey($workspace), 'list_roles')['data'];
    $custom = collect($data['customRoles'])->keyBy('name');
    expect($data['hasCustomRoles'])->toBeTrue()
        ->and($custom->keys()->all())->toBe(['Auditor', 'Ops'])
        ->and($custom['Ops'])->toMatchArray(['value' => "custom:{$ops->id}", 'id' => $ops->id, 'description' => 'Runs the servers', 'inUse' => true, 'members' => 2])
        ->and(collect($custom['Ops']['permissions'])->pluck('value')->all())->toBe(['manage-settings', 'manage-machines'])
        ->and($custom['Auditor'])->toMatchArray(['permissions' => [], 'members' => 0])
        ->and(collect($data['roles'])->firstWhere('value', 'viewer')['members'])->toBe(1);

    $workspace->forceFill(['plan' => Plan::Pro])->save();
    $data = mcpCall($workspace, mcpKey($workspace), 'list_roles')['data'];
    expect($data['hasCustomRoles'])->toBeFalse()
        ->and(collect($data['customRoles'])->every(fn (array $r) => $r['inUse'] === false))->toBeTrue()
        ->and(collect($data['roles'])->firstWhere('value', 'viewer')['members'])->toBe(3)
        ->and(implode(' ', $data['notes']))->toContain('Custom roles are on Business');
});

test('invite_member invites by email with a role, sends the email and records it', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create(['name' => 'Northwind']);
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'invite_member', ['email' => ' Sam@Example.com ', 'role' => 'Viewer']);
    expect($result['error'])->toBeFalse();

    $invitation = $workspace->invitations()->sole();
    expect($invitation->email)->toBe('sam@example.com')
        ->and($invitation->role->value)->toBe('viewer')
        ->and($invitation->custom_role_id)->toBeNull()
        ->and($invitation->inviter->is($workspace->owner))->toBeTrue()
        ->and($result['data']['invitation'])->toMatchArray(['id' => $invitation->id, 'email' => 'sam@example.com', 'role' => 'viewer', 'roleLabel' => 'Viewer', 'invitedBy' => $workspace->owner->name, 'expired' => false])
        ->and($result['data']['resent'])->toBeFalse()
        ->and($result['data']['note'])->toContain('Invite sent to sam@example.com');

    $plainToken = null;
    Notification::assertSentOnDemand(WorkspaceInvitationSent::class, function (WorkspaceInvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation, &$plainToken) {
        $plainToken = $notification->plainToken;
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'sam@example.com'
            && $mail->subject === 'Join Northwind on ServerOS'
            && WorkspaceInvitation::findByToken($notification->plainToken)?->is($invitation);
    });
    // The link is only in the email.
    expect($result['text'])->not->toContain($plainToken)->not->toContain($invitation->token);

    $activity = teamToolsActivity($workspace);
    expect($activity->action)->toBe('member.invite')
        ->and($activity->subject)->toBe('sam@example.com')
        ->and($activity->user_id)->toBe($workspace->owner_id)
        ->and($activity->meta)->toMatchArray(['role' => 'viewer', 'via' => 'mcp']);
});

test('inviting the same email again refreshes the invite and sends it again', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    mcpCall($workspace, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'viewer']);
    $first = $workspace->invitations()->sole();
    $first->forceFill(['expires_at' => now()->addDay()])->save();

    $result = mcpCall($workspace, $key, 'invite_member', ['email' => 'SAM@example.com', 'role' => 'admin']);
    $invitation = $workspace->invitations()->sole();

    expect($result['data']['resent'])->toBeTrue()
        ->and($result['data']['note'])->toContain('again')
        ->and($invitation->id)->toBe($first->id)
        ->and($invitation->token)->not->toBe($first->token)
        ->and($invitation->role->value)->toBe('admin')
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
    Notification::assertSentOnDemandTimes(WorkspaceInvitationSent::class, 2);
});

test('invite_member follows the plan’s role rules and refuses existing members', function () {
    Notification::fake();
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    $key = mcpKey($essential);

    expect(mcpCall($essential, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'member'])['text'])
        ->toContain('Member and Viewer roles are on Pro and Business. Invite them as an Admin, or upgrade.')
        ->and(mcpCall($essential, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'viewer'])['error'])->toBeTrue()
        ->and($essential->invitations()->count())->toBe(0);

    expect(mcpCall($essential, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'admin'])['error'])->toBeFalse()
        ->and($essential->invitations()->sole()->role->value)->toBe('admin');

    // Already in the workspace, whatever the case.
    $result = mcpCall($essential, $key, 'invite_member', ['email' => strtoupper($essential->owner->email), 'role' => 'admin']);
    expect($result['text'])->toContain('They’re already in this workspace.');

    // Both problems at once, as the form shows them.
    $both = mcpCall($essential, $key, 'invite_member', ['email' => $essential->owner->email, 'role' => 'viewer'])['text'];
    expect($both)->toContain('Member and Viewer roles')->toContain('already in this workspace');

    Notification::assertSentOnDemandTimes(WorkspaceInvitationSent::class, 1);
});

test('invite_member checks its arguments', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'invite_member', ['email' => 'not-an-email', 'role' => 'viewer'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'invite_member', ['role' => 'viewer'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'sam@example.com'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'owner'])['text'])->toContain('The Owner role can’t be given')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'sam@example.com', 'role' => 'superuser'])['text'])->toContain('Pick a role')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => str_repeat('a', 250).'@example.com', 'role' => 'admin'])['error'])->toBeTrue()
        ->and($workspace->invitations()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('invite_member gives custom roles on Business only, and only this workspace’s', function () {
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $role = $workspace->customRoles()->create(['name' => 'Billing manager', 'permissions' => ['manage-billing']]);
    $other = Workspace::factory()->onPlan(Plan::Business)->create();
    $theirs = $other->customRoles()->create(['name' => 'Theirs', 'permissions' => ['manage-machines']]);
    $key = mcpKey($workspace);

    $byName = mcpCall($workspace, $key, 'invite_member', ['email' => 'kim@example.com', 'role' => 'billing MANAGER']);
    expect($byName['error'])->toBeFalse()
        ->and($byName['data']['invitation'])->toMatchArray(['role' => 'viewer', 'roleLabel' => 'Billing manager'])
        ->and(WorkspaceInvitation::query()->where('email', 'kim@example.com')->sole()->custom_role_id)->toBe($role->id)
        ->and(teamToolsActivity($workspace)->meta['role'])->toBe('Billing manager');

    $byId = mcpCall($workspace, $key, 'invite_member', ['email' => 'lee@example.com', 'role' => "custom:{$role->id}"]);
    expect($byId['error'])->toBeFalse()
        ->and(WorkspaceInvitation::query()->where('email', 'lee@example.com')->sole()->custom_role_id)->toBe($role->id);

    expect(mcpCall($workspace, $key, 'invite_member', ['email' => 'max@example.com', 'role' => "custom:{$theirs->id}"])['text'])->toContain('That role no longer exists')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'max@example.com', 'role' => 'Theirs'])['text'])->toContain('Pick a role');

    $workspace->forceFill(['plan' => Plan::Pro])->save();
    expect(mcpCall($workspace, $key, 'invite_member', ['email' => 'max@example.com', 'role' => "custom:{$role->id}"])['text'])->toContain('Custom roles are on Business.')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'max@example.com', 'role' => 'Billing manager'])['text'])->toContain('Custom roles are on Business.')
        ->and(WorkspaceInvitation::query()->where('email', 'max@example.com')->exists())->toBeFalse();
});

test('invite_member is limited to 20 invites a minute', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    foreach (range(1, 20) as $i) {
        expect(mcpCall($workspace, $key, 'invite_member', ['email' => "p{$i}@example.com", 'role' => 'viewer'])['error'])->toBeFalse();
    }
    expect(mcpCall($workspace, $key, 'invite_member', ['email' => 'p21@example.com', 'role' => 'viewer'])['text'])->toContain('a lot of invites')
        ->and($workspace->invitations()->count())->toBe(20);
});

test('read-only connections cannot invite', function () {
    Notification::fake();
    $workspace = Workspace::factory()->create();

    expect(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'invite_member', ['email' => 'sam@example.com', 'role' => 'viewer'])['text'])->toContain('read-only')
        ->and($workspace->invitations()->count())->toBe(0);
});

test('cancel_invitation revokes an invite by id or email, only in this workspace', function () {
    $workspace = Workspace::factory()->create();
    $first = WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'sam@example.com']);
    $second = WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'kim@example.com']);
    $elsewhere = WorkspaceInvitation::factory()->create(['email' => 'lee@example.com']);
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'cancel_invitation', ['invitation' => (string) $first->id]);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['cancelled'])->toBe(['id' => $first->id, 'email' => 'sam@example.com'])
        ->and(WorkspaceInvitation::find($first->id))->toBeNull();
    $activity = teamToolsActivity($workspace);
    expect($activity->action)->toBe('member.uninvite')
        ->and($activity->subject)->toBe('sam@example.com')
        ->and($activity->meta['via'])->toBe('mcp');

    expect(mcpCall($workspace, $key, 'cancel_invitation', ['invitation' => 'KIM@example.com'])['error'])->toBeFalse()
        ->and(WorkspaceInvitation::find($second->id))->toBeNull();

    // Another workspace's invite, by id or email: not found, and kept.
    expect(mcpCall($workspace, $key, 'cancel_invitation', ['invitation' => $elsewhere->id])['text'])->toContain('No pending invite')
        ->and(mcpCall($workspace, $key, 'cancel_invitation', ['invitation' => 'lee@example.com'])['text'])->toContain('No pending invite')
        ->and(WorkspaceInvitation::find($elsewhere->id))->not->toBeNull();

    // Someone who already joined.
    expect(mcpCall($workspace, $key, 'cancel_invitation', ['invitation' => $workspace->owner->email])['text'])->toContain('already joined')
        ->and(mcpCall($workspace, $key, 'cancel_invitation', [])['text'])->toContain('Invalid arguments');
});

test('members cannot cancel invites', function () {
    $workspace = Workspace::factory()->create();
    $invitation = WorkspaceInvitation::factory()->for($workspace)->create();

    expect(mcpCall($workspace, mcpKey($workspace, teamToolsJoin($workspace, 'member')), 'cancel_invitation', ['invitation' => $invitation->id])['text'])->toContain('doesn’t allow that')
        ->and(WorkspaceInvitation::find($invitation->id))->not->toBeNull();
});

test('change_member_role changes a member’s role and records it', function () {
    $workspace = Workspace::factory()->create();
    $admin = teamToolsJoin($workspace, 'admin');
    $member = teamToolsJoin($workspace, 'member', ['name' => 'Bea', 'email' => 'bea@example.com']);
    $key = mcpKey($workspace, $admin);

    $result = mcpCall($workspace, $key, 'change_member_role', ['member' => 'BEA@example.com', 'role' => 'viewer']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['member'])->toMatchArray(['id' => $member->id, 'role' => 'viewer', 'roleLabel' => 'Viewer'])
        ->and($result['data']['previousRole'])->toBe('Member')
        ->and($result['data']['changed'])->toBeTrue()
        ->and($result['data']['note'])->toBe('Bea now has the Viewer role.')
        ->and($workspace->roleOf($member)->value)->toBe('viewer');

    $activity = teamToolsActivity($workspace);
    expect($activity->action)->toBe('member.role')
        ->and($activity->subject)->toBe('Bea')
        ->and($activity->user_id)->toBe($admin->id)
        ->and($activity->meta)->toMatchArray(['role' => 'Viewer', 'via' => 'mcp']);

    // By id and by name, too; the same role again changes nothing.
    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $member->id, 'role' => 'admin'])['data']['member']['role'])->toBe('admin')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => 'bea', 'role' => 'member'])['data']['member']['role'])->toBe('member');
    $count = Activity::query()->where('action', 'member.role')->count();
    $again = mcpCall($workspace, $key, 'change_member_role', ['member' => 'bea@example.com', 'role' => 'member']);
    expect($again['data']['changed'])->toBeFalse()
        ->and($again['data']['note'])->toContain('already has')
        ->and(Activity::query()->where('action', 'member.role')->count())->toBe($count);
});

test('change_member_role protects the owner and your own role', function () {
    $workspace = Workspace::factory()->create();
    $admin = teamToolsJoin($workspace, 'admin');
    $key = mcpKey($workspace, $admin);

    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $workspace->owner->email, 'role' => 'viewer'])['text'])->toContain('The owner’s role can’t be changed.')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => $admin->email, 'role' => 'viewer'])['text'])->toContain('You can’t change your own role.')
        ->and(mcpCall($workspace, mcpKey($workspace), 'change_member_role', ['member' => $admin->email, 'role' => 'owner'])['text'])->toContain('The Owner role can’t be given')
        ->and($workspace->roleOf($workspace->owner)->value)->toBe('owner')
        ->and($workspace->roleOf($admin)->value)->toBe('admin');
});

test('change_member_role follows the plan and keeps to this workspace', function () {
    $essential = Workspace::factory()->onPlan(Plan::Essential)->create();
    $admin = teamToolsJoin($essential, 'admin');
    expect(mcpCall($essential, mcpKey($essential), 'change_member_role', ['member' => $admin->email, 'role' => 'member'])['text'])->toContain('Member and Viewer roles are on Pro and Business.')
        ->and($essential->roleOf($admin)->value)->toBe('admin');

    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $sam = teamToolsJoin($workspace, 'member', ['name' => 'Sam']);
    $role = $workspace->customRoles()->create(['name' => 'Billing manager', 'permissions' => ['manage-billing']]);
    $other = Workspace::factory()->onPlan(Plan::Business)->create();
    $theirs = $other->customRoles()->create(['name' => 'Ops', 'permissions' => ['manage-machines']]);
    $stranger = teamToolsJoin($other, 'admin', ['email' => 'stranger@example.com']);
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'change_member_role', ['member' => $sam->email, 'role' => "custom:{$role->id}"]);
    expect($result['data']['member'])->toMatchArray(['role' => 'viewer', 'roleLabel' => 'Billing manager'])
        ->and(teamToolsActivity($workspace)->meta['role'])->toBe('Billing manager')
        ->and($workspace->fresh()->allows($sam, WorkspacePermission::ManageBilling))->toBeTrue()
        ->and($workspace->fresh()->allows($sam, WorkspacePermission::ManageMachines))->toBeFalse();

    // Back to a built-in role clears the custom one.
    mcpCall($workspace, $key, 'change_member_role', ['member' => $sam->email, 'role' => 'member']);
    expect($workspace->members()->whereKey($sam->id)->first()->pivot->custom_role_id)->toBeNull();

    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $sam->email, 'role' => "custom:{$theirs->id}"])['text'])->toContain('That role no longer exists')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => (string) $stranger->id, 'role' => 'viewer'])['text'])->toContain('No member')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => 'stranger@example.com', 'role' => 'viewer'])['text'])->toContain('No member')
        ->and($other->roleOf($stranger)->value)->toBe('admin');

    $workspace->forceFill(['plan' => Plan::Pro])->save();
    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $sam->email, 'role' => 'Billing manager'])['text'])->toContain('Custom roles are on Business.');
});

test('change_member_role says when the person has only been invited, or names several people', function () {
    $workspace = Workspace::factory()->create();
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'kim@example.com']);
    teamToolsJoin($workspace, 'member', ['name' => 'Alex', 'email' => 'alex1@example.com']);
    teamToolsJoin($workspace, 'viewer', ['name' => 'Alex', 'email' => 'alex2@example.com']);
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => 'kim@example.com', 'role' => 'admin'])['text'])->toContain('hasn’t joined yet')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => 'alex', 'role' => 'admin'])['text'])->toContain('Several members are called')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => '', 'role' => 'admin'])['error'])->toBeTrue();
});

test('remove_member needs the member’s email to confirm, and records the removal', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $admin = teamToolsJoin($workspace, 'admin');
    $member = teamToolsJoin($workspace, 'member', ['name' => 'Bea', 'email' => 'bea@example.com']);
    $memberKey = mcpKey($workspace, $member);
    $key = mcpKey($workspace, $admin);

    expect(mcpCall($workspace, $key, 'remove_member', ['member' => 'bea@example.com'])['text'])->toContain('confirm set to the member’s email address exactly: “bea@example.com”')
        ->and(mcpCall($workspace, $key, 'remove_member', ['member' => 'bea@example.com', 'confirm' => 'Bea'])['text'])->toContain('confirm doesn’t match')
        ->and($workspace->hasMember($member))->toBeTrue();

    $result = mcpCall($workspace, $key, 'remove_member', ['member' => (string) $member->id, 'confirm' => ' BEA@example.com ']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['removed'])->toBe(['id' => $member->id, 'name' => 'Bea', 'email' => 'bea@example.com'])
        ->and($result['data']['left'])->toBeFalse()
        ->and($workspace->hasMember($member))->toBeFalse()
        ->and($member->fresh())->not->toBeNull();

    $activity = teamToolsActivity($workspace);
    expect($activity->action)->toBe('member.remove')
        ->and($activity->subject)->toBe('Bea')
        ->and($activity->user_id)->toBe($admin->id)
        ->and($activity->meta['via'])->toBe('mcp');

    // Their own connection stops working.
    mcpRequest($workspace, $memberKey, 'tools/list')->assertForbidden();
});

test('the owner cannot be removed and cannot leave', function () {
    $workspace = Workspace::factory()->create();
    $admin = teamToolsJoin($workspace, 'admin');
    $owner = $workspace->owner;

    expect(mcpCall($workspace, mcpKey($workspace, $admin), 'remove_member', ['member' => $owner->email, 'confirm' => $owner->email])['text'])->toContain('The owner can’t be removed.')
        ->and(mcpCall($workspace, mcpKey($workspace), 'remove_member', ['member' => $owner->email, 'confirm' => $owner->email])['text'])->toContain('Owners can’t leave their own workspace.')
        // Said before asking anyone to confirm.
        ->and(mcpCall($workspace, mcpKey($workspace, $admin), 'remove_member', ['member' => $owner->email])['text'])->toContain('The owner can’t be removed.')
        ->and($workspace->hasMember($owner))->toBeTrue();
});

test('removing yourself is leaving the workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $admin = teamToolsJoin($workspace, 'admin', ['name' => 'Ada', 'email' => 'ada@example.com']);
    $key = mcpKey($workspace, $admin);

    $result = mcpCall($workspace, $key, 'remove_member', ['member' => 'ada@example.com', 'confirm' => 'ada@example.com']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['left'])->toBeTrue()
        ->and($result['data']['note'])->toContain('You’ve left Acme')
        ->and($workspace->hasMember($admin))->toBeFalse()
        ->and(teamToolsActivity($workspace)->action)->toBe('member.leave');

    mcpRequest($workspace, $key, 'tools/list')->assertForbidden();
});

test('remove_member keeps to this workspace', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $stranger = teamToolsJoin($other, 'member', ['email' => 'stranger@example.com']);

    expect(mcpCall($workspace, mcpKey($workspace), 'remove_member', ['member' => (string) $stranger->id, 'confirm' => 'stranger@example.com'])['text'])->toContain('No member')
        ->and(mcpCall($workspace, mcpKey($workspace), 'remove_member', ['member' => 'stranger@example.com', 'confirm' => 'stranger@example.com'])['text'])->toContain('No member')
        ->and($other->hasMember($stranger))->toBeTrue();
});

test('update_workspace renames the workspace like the General tab', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old', 'slug' => 'old-co']);
    $admin = teamToolsJoin($workspace, 'admin');
    $key = mcpKey($workspace, $admin);

    $result = mcpCall($workspace, $key, 'update_workspace', ['name' => '  Northwind  ']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['workspace'])->toMatchArray(['name' => 'Northwind', 'slug' => 'old-co', 'logoUrl' => null])
        ->and($result['data']['changed'])->toBe(['name'])
        ->and($workspace->fresh()->name)->toBe('Northwind')
        ->and(Activity::query()->where('action', 'workspace.rename')->sole()->only(['subject', 'meta', 'source']))
        ->toMatchArray(['subject' => 'Northwind', 'source' => 'mcp'])
        ->and(Activity::query()->where('action', 'workspace.rename')->sole()->meta)->toMatchArray(['from' => 'Old', 'via' => 'mcp']);

    expect(mcpCall($workspace, $key, 'update_workspace', ['name' => 'Northwind'])['data']['changed'])->toBe([])
        ->and(mcpCall($workspace, $key, 'update_workspace', ['name' => '   '])['text'])->toContain('The name can’t be empty.')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['name' => str_repeat('a', 256)])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'update_workspace', [])['text'])->toContain('Say what to change')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['name' => 'X', 'slug' => 'new-co'])['text'])->toContain('address can’t be changed')
        ->and($workspace->fresh()->only(['name', 'slug']))->toBe(['name' => 'Northwind', 'slug' => 'old-co']);
});

test('members and read-only connections cannot change the workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old']);

    expect(mcpCall($workspace, mcpKey($workspace, teamToolsJoin($workspace, 'member')), 'update_workspace', ['name' => 'Hijacked'])['text'])->toContain('Edit workspace settings')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'update_workspace', ['name' => 'Hijacked'])['text'])->toContain('read-only')
        ->and($workspace->fresh()->name)->toBe('Old');
});

test('update_workspace sets the logo from a public image and removes it', function () {
    Storage::fake('public');
    teamToolsImages();
    $workspace = Workspace::factory()->create(['logo_path' => 'workspace-logos/old.png']);
    Storage::disk('public')->put('workspace-logos/old.png', 'old');
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'update_workspace', ['logo_url' => 'https://cdn.example.com/logo.png', 'name' => 'Northwind']);
    expect($result['error'])->toBeFalse();
    $path = $workspace->fresh()->logo_path;
    expect($path)->toStartWith('workspace-logos/')->not->toBe('workspace-logos/old.png')
        ->and($result['data']['changed'])->toBe(['name', 'logo'])
        ->and($result['data']['workspace']['logoUrl'])->toBe(Storage::disk('public')->url($path));
    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertMissing('workspace-logos/old.png');

    // Bad images change nothing, not even the name given with them.
    expect(mcpCall($workspace, $key, 'update_workspace', ['logo_url' => 'https://cdn.example.com/logo.svg', 'name' => 'Other'])['text'])->toContain('isn’t a PNG')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['logo_url' => 'https://internal.example.com/logo.png'])['text'])->toContain('private network')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['logo_url' => 'http://cdn.example.com/logo.png'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'update_workspace', ['logo_url' => 'https://cdn.example.com/logo.png', 'remove_logo' => true])['text'])->toContain('not both')
        ->and($workspace->fresh()->only(['name', 'logo_path']))->toBe(['name' => 'Northwind', 'logo_path' => $path]);

    $removed = mcpCall($workspace, $key, 'update_workspace', ['remove_logo' => true]);
    expect($removed['data']['changed'])->toBe(['logo'])
        ->and($removed['data']['workspace']['logoUrl'])->toBeNull()
        ->and($workspace->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
    expect(mcpCall($workspace, $key, 'update_workspace', ['remove_logo' => true])['data']['changed'])->toBe([]);
});

test('get_my_notifications shows the person’s own email choices', function () {
    $workspace = Workspace::factory()->create();
    $viewer = teamToolsJoin($workspace, 'viewer');

    $owner = mcpCall($workspace, mcpKey($workspace), 'get_my_notifications')['data'];
    expect($owner)->toMatchArray([
        'alertEmails' => 'all', 'alertEmailsLabel' => 'Everything', 'weeklySummary' => true,
        'getsAlerts' => true, 'workspaceSendsAlertEmails' => true,
        'url' => 'https://'.$workspace->host().'/settings/profile',
    ]);

    $workspace->members()->updateExistingPivot($viewer->id, ['alert_emails' => 'none', 'weekly_summary' => false]);
    $mine = mcpCall($workspace, mcpKey($workspace, $viewer, [Scopes::READ]), 'get_my_notifications')['data'];
    expect($mine)->toMatchArray(['alertEmails' => 'none', 'alertEmailsLabel' => 'None', 'weeklySummary' => false, 'getsAlerts' => false])
        ->and(implode(' ', $mine['notes']))->toContain('Alerts go to owners and admins');

    $workspace->forceFill(['alert_email' => false])->save();
    $off = mcpCall($workspace, mcpKey($workspace), 'get_my_notifications')['data'];
    expect($off['workspaceSendsAlertEmails'])->toBeFalse()
        ->and(implode(' ', $off['notes']))->toContain('turned off for the whole workspace');
});

test('update_my_notifications changes only the person’s own choices, in this workspace', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $viewer = teamToolsJoin($workspace, 'viewer');
    $other->members()->attach($viewer, ['role' => 'admin']);
    $key = mcpKey($workspace, $viewer);

    $result = mcpCall($workspace, $key, 'update_my_notifications', ['weekly_summary' => false]);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['alertEmails' => 'all', 'weeklySummary' => false, 'getsAlerts' => false]);

    $result = mcpCall($workspace, $key, 'update_my_notifications', ['alert_emails' => 'problems']);
    expect($result['data'])->toMatchArray(['alertEmails' => 'problems', 'alertEmailsLabel' => 'Only problems', 'weeklySummary' => false]);

    $pivot = fn (Workspace $w, User $u) => $w->members()->withPivot(['alert_emails', 'weekly_summary'])->whereKey($u->id)->first()->pivot;
    expect($pivot($workspace, $viewer)->alert_emails)->toBe('problems')
        ->and((bool) $pivot($workspace, $viewer)->weekly_summary)->toBeFalse()
        // Not anyone else's, and not in the person's other workspace.
        ->and($pivot($workspace, $workspace->owner)->alert_emails)->toBe('all')
        ->and((bool) $pivot($workspace, $workspace->owner)->weekly_summary)->toBeTrue()
        ->and($pivot($other, $viewer)->alert_emails)->toBe('all')
        ->and((bool) $pivot($other, $viewer)->weekly_summary)->toBeTrue();

    // Both at once.
    expect(mcpCall($workspace, $key, 'update_my_notifications', ['alert_emails' => 'none', 'weekly_summary' => true])['data'])
        ->toMatchArray(['alertEmails' => 'none', 'weeklySummary' => true]);
});

test('update_my_notifications checks its arguments and needs a connection that may make changes', function () {
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'update_my_notifications', [])['text'])->toContain('Say what to change')
        ->and(mcpCall($workspace, $key, 'update_my_notifications', ['alert_emails' => 'some'])['text'])->toContain('alert_emails must be all, problems or none.')
        ->and(mcpCall($workspace, $key, 'update_my_notifications', ['weekly_summary' => 'often'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'update_my_notifications', ['weekly_summary' => false])['text'])->toContain('read-only')
        ->and((bool) $workspace->members()->withPivot('weekly_summary')->whereKey($workspace->owner_id)->first()->pivot->weekly_summary)->toBeTrue();
});
