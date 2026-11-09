<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Scopes;
use App\Mcp\Tools\WorkspaceTools;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => config()->set('mcp.groups', [WorkspaceTools::class]));

function mcpSettings(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/settings/mcp'.$path;
}

test('keys need the experimental terms accepted first, and are shown once', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Laptop', 'access' => 'write'])->assertStatus(422);

    $owner->forceFill(['mcp_terms_accepted_at' => now()])->save();
    $secret = $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Laptop', 'access' => 'write'])
        ->assertCreated()->json('secret');

    expect($secret)->toStartWith(McpToken::KEY_PREFIX)
        ->and(McpToken::query()->sole())->scopes->toBe([Scopes::READ, Scopes::WRITE])->token_hash->not->toBe($secret)
        ->and(Activity::query()->where('action', 'mcp.key_create')->exists())->toBeTrue();
    mcpRequest($workspace, $secret, 'ping')->assertOk();

    // Commands need two-factor.
    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands'])->assertStatus(422)->assertJsonValidationErrors('access');

    $this->actingAs($owner)->get(mcpSettings($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/Mcp')
            ->where('mcpUrl', 'https://'.$workspace->host().'/mcp')
            ->where('connections.0.name', 'Laptop')
            ->where('connections.0.prefix', substr($secret, 0, 12))
            ->missing('connections.0.secret'));
});

test('people see their own connections; those who manage members see and revoke everyone’s', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['mcp_terms_accepted_at' => now()]);
    $workspace->members()->attach($member, ['role' => WorkspaceRole::Member->value]);
    [$ownersKey] = McpToken::issueKey($workspace, $workspace->owner, 'Owner key', Scopes::ALL);
    [$membersKey, $secret] = McpToken::issueKey($workspace, $member, 'Member key', [Scopes::READ]);

    $this->actingAs($member)->get(mcpSettings($workspace))->assertInertia(fn (Assert $page) => $page->has('connections', 1)->where('connections.0.name', 'Member key'));
    $this->actingAs($member)->delete(mcpSettings($workspace, "/connections/{$ownersKey->id}"))->assertForbidden();

    $this->actingAs($workspace->owner)->get(mcpSettings($workspace))->assertInertia(fn (Assert $page) => $page->has('connections', 2)->where('seesAll', true));
    $this->actingAs($workspace->owner)->delete(mcpSettings($workspace, "/connections/{$membersKey->id}"))->assertRedirect();
    mcpRequest($workspace, $secret, 'ping')->assertUnauthorized();
});

test('another workspace’s connection can’t be touched from here', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    [$key] = McpToken::issueKey($other, $other->owner, 'Theirs', Scopes::ALL);

    $this->actingAs($workspace->owner)->delete(mcpSettings($workspace, "/connections/{$key->id}"))->assertNotFound();
    expect($key->fresh()->revoked_at)->toBeNull();
});
