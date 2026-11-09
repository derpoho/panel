<?php

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Mcp\Scopes;
use App\Mcp\Tools\WorkspaceTools;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Mcp\OAuth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => config()->set('mcp.groups', [WorkspaceTools::class]));

function oauthClient(array $uris = ['https://claude.ai/api/mcp/auth_callback'], string $name = 'Claude'): McpOAuthClient
{
    $response = test()->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => $uris])->assertCreated();

    return McpOAuthClient::query()->where('client_id', $response->json('client_id'))->sole();
}

/** @return array{0: string, 1: string} verifier and its S256 challenge */
function pkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, OAuthTokenController::challenge($verifier)];
}

/**
 * The consent page for a request, and the nonce to approve it with.
 *
 * @param  array<string, string>  $extra
 */
function authorizeRequest(User $user, McpOAuthClient $client, string $challenge, array $extra = []): string
{
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $client->client_id,
        'redirect_uri' => $client->redirect_uris[0],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'xyz',
        'scope' => implode(' ', Scopes::ALL),
        ...$extra,
    ]);
    $nonce = null;
    test()->actingAs($user)->get('/oauth/authorize?'.$query)
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(function (Assert $page) use (&$nonce) {
            $page->component('auth/OAuthAuthorize');
            $nonce = $page->toArray()['props']['request'] ?? null;
        });

    return (string) $nonce;
}

/** Approve, and pull the code out of the redirect. */
function approve(User $user, string $nonce, array $choices = ['write' => true]): string
{
    $location = test()->actingAs($user)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', ...$choices])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect($params['state'])->toBe('xyz')->and($params['iss'])->toBe(OAuth::issuer());

    return $params['code'];
}

test('discovery: protected resource and authorization server metadata', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson('https://'.$workspace->host().'/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', 'https://'.$workspace->host().'/mcp')
        ->assertJsonPath('authorization_servers.0', OAuth::issuer());

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', OAuth::issuer())
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
});

test('registration only takes safe redirect URIs', function () {
    $this->postJson('/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://evil.example/cb']])->assertStatus(400);
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://a.example/cb#frag']])->assertStatus(400);
    $this->postJson('/oauth/register', ['redirect_uris' => []])->assertStatus(400);

    $this->postJson('/oauth/register', ['client_name' => 'Cursor', 'redirect_uris' => ['cursor://anysphere.cursor-retrieval/oauth/callback', 'http://127.0.0.1:33418/callback']])
        ->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('client_name', 'Cursor');
});

test('the whole flow: consent, code with PKCE, MCP access, refresh rotation, revoke', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $user = $workspace->owner;
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $nonce = authorizeRequest($user, $client, $challenge, ['resource' => OAuth::resource($workspace)]);
    $code = approve($user, $nonce);

    // The wrong verifier, or a second use, gets nothing.
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => Str::random(64)])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    // A failed attempt doesn't burn the code... but a used one can't be reused.
    $tokens = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])
        ->assertOk()->assertHeader('Cache-Control')->json();
    expect($tokens['token_type'])->toBe('Bearer')->and($tokens['scope'])->toBe('workspace:read workspace:write');
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])
        ->assertStatus(400);

    // The access token works on this workspace's MCP server, as the person.
    $result = mcpCall($workspace, $tokens['access_token'], 'get_workspace');
    expect($result['data']['connection']['name'])->toBe('Claude')
        ->and($result['data']['connection']['canMakeChanges'])->toBeTrue()
        ->and($result['data']['connection']['canRunCommands'])->toBeFalse();

    // Refresh tokens rotate: the old pair stops working.
    $fresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertOk()->json();
    mcpRequest($workspace, $tokens['access_token'], 'ping')->assertUnauthorized();
    mcpRequest($workspace, $fresh['access_token'], 'ping')->assertOk();
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertStatus(400);

    // Revoking ends it.
    $this->postJson('/oauth/revoke', ['token' => $fresh['refresh_token'], 'client_id' => $client->client_id])->assertOk();
    mcpRequest($workspace, $fresh['access_token'], 'ping')->assertUnauthorized();
});

test('tokens are bound to the workspace approved, and expire', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->for($workspace->owner, 'owner')->create();
    $other->members()->syncWithoutDetaching([$workspace->owner_id => ['role' => WorkspaceRole::Owner->value]]);
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $code = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge, ['resource' => OAuth::resource($workspace)]));
    $access = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])->json('access_token');

    mcpRequest($other, $access, 'ping')->assertUnauthorized();
    mcpRequest($workspace, $access, 'ping')->assertOk();

    $this->travel(McpToken::ACCESS_MINUTES + 1)->minutes();
    mcpRequest($workspace, $access, 'ping')->assertUnauthorized();
});

test('the person chooses: read only by default without changes ticked, and no commands without two-factor', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $code = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge), ['workspace' => $workspace->id, 'write' => false]);
    expect(McpOAuthCode::query()->sole()->scopes)->toBe([Scopes::READ]);

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id, 'write' => true, 'commands' => true])
        ->assertSessionHasErrors('commands');
});

test('deny goes back with access_denied; bad clients and redirects never redirect', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [, $challenge] = pkce();

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $location = $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'deny'])->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith($client->redirect_uris[0].'?error=access_denied');

    // The nonce is single use.
    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id])
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    // Unknown client, unregistered redirect: shown, not redirected.
    $this->actingAs($workspace->owner)->get('/oauth/authorize?client_id=nope&response_type=code')->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => 'https://evil.example/cb', 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    // No PKCE: back to the app with an error.
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code', 'state' => 's']))
        ->assertRedirect()->assertRedirectContains('error=invalid_request');
});

test('a workspace you are not in can’t be approved', function () {
    $workspace = Workspace::factory()->create();
    $stranger = User::factory()->create();
    Workspace::factory()->for($stranger, 'owner')->create();
    $client = oauthClient();
    [, $challenge] = pkce();

    $this->actingAs($stranger)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => OAuth::resource($workspace)]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    $nonce = authorizeRequest($stranger, $client, $challenge);
    $this->actingAs($stranger)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id])->assertSessionHasErrors('workspace');
});

test('the consent page says when it can vouch for the app', function () {
    $workspace = Workspace::factory()->create();
    [, $challenge] = pkce();

    $claude = oauthClient();
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $claude->client_id, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertInertia(fn (Assert $page) => $page->where('client.known', 'claude')->where('client.redirectHost', 'claude.ai'));

    $unknown = oauthClient(['https://totally-claude.example/cb'], 'Claude');
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $unknown->client_id, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertInertia(fn (Assert $page) => $page->where('client.known', null));
});

test('spent MCP access is cleared away, live connections are kept', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    $idle = oauthClient(['https://example.com/cb'], 'Never used');
    $idle->forceFill(['created_at' => now()->subDays(40)])->save();
    $live = McpToken::query()->forceCreate([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->owner->id, 'mcp_oauth_client_id' => $client->id,
        'kind' => 'oauth', 'name' => 'Claude', 'scopes' => Scopes::ALL, 'token_hash' => McpToken::hash('a'), 'token_prefix' => 'soa_a',
        'expires_at' => now()->subHour(), 'refresh_hash' => McpToken::hash('r'), 'refresh_expires_at' => now()->addDays(20),
    ]);
    $ended = $live->replicate()->forceFill(['token_hash' => McpToken::hash('b'), 'refresh_hash' => McpToken::hash('s'), 'refresh_expires_at' => now()->subDays(31)]);
    $ended->save();
    [$revoked] = McpToken::issueKey($workspace, $workspace->owner, 'Old key', Scopes::ALL);
    $revoked->forceFill(['revoked_at' => now()->subDays(31)])->save();
    $call = McpToolCall::query()->forceCreate(['workspace_id' => $workspace->id, 'tool' => 'get_workspace', 'arguments' => [], 'ok' => true, 'duration_ms' => 1, 'created_at' => now()->subDays(31)]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(McpToken::query()->pluck('id')->all())->toBe([$live->id])
        ->and(McpToolCall::query()->find($call->id))->toBeNull()
        ->and($client->fresh())->not->toBeNull()
        ->and($idle->fresh())->toBeNull();
});

test('connecting an app through MCP shows in the workspace’s Activity; saying no does not', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $client = oauthClient(name: 'Claude Desktop');
    [, $challenge] = pkce();

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'deny']);
    approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge), ['workspace' => $workspace->id, 'write' => false]);

    $entry = Activity::query()->sole();
    expect($entry->action)->toBe('mcp.connect')
        ->and($entry->meta)->toBe(['scopes' => [Scopes::READ]])
        ->and(ActivityLine::sentence($entry))->toBe('Sam connected Claude Desktop through MCP');
});

test('a revoke from the app is its own doing, and a token for a package’s audience has no workspace to tell', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $sam = $workspace->owner;
    $sam->forceFill(['name' => 'Sam'])->save();
    $boss = User::factory()->admin()->create();
    $client = oauthClient();
    $issue = function (?Workspace $for, User $user) use ($client): string {
        $secret = McpToken::secret('access');
        McpToken::query()->create([
            'audience' => $for === null ? 'tickets' : McpToken::WORKSPACE,
            'workspace_id' => $for?->id,
            'user_id' => $user->id,
            'mcp_oauth_client_id' => $client->id,
            'kind' => 'oauth',
            'name' => $client->name,
            'scopes' => $for === null ? [] : Scopes::ALL,
            'token_hash' => McpToken::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
            'expires_at' => now()->addHour(),
        ]);

        return $secret;
    };

    $this->postJson('/oauth/revoke', ['token' => $issue($workspace, $sam), 'client_id' => $client->client_id])->assertOk();
    $this->postJson('/oauth/revoke', ['token' => $issue(null, $boss), 'client_id' => $client->client_id])->assertOk();

    expect(McpToken::query()->whereNull('revoked_at')->count())->toBe(0);
    // One line, in the workspace, not credited to Sam: the app ended it.
    $gone = Activity::query()->sole();
    expect($gone->workspace_id)->toBe($workspace->id)
        ->and($gone->user_id)->toBeNull()
        ->and($gone->source)->toBe('api')
        ->and($gone->ip)->toBeNull()
        ->and(ActivityLine::sentence($gone))->toBe('Claude disconnected itself from MCP (Sam’s connection)');
});
