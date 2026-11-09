<?php

use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Mcp\Scopes;
use App\Models\McpOAuthClient;
use App\Models\McpToken;
use App\Models\User;
use App\Support\Extensions\Extensions;
use App\Support\Mcp\OAuth;
use App\Support\Mcp\OAuthAudience;
use App\Support\Mcp\OAuthConsent;
use App\Support\Mcp\OAuthGrant;
use App\Support\Mcp\OAuthPage;
use App\Support\Mcp\WorkspaceAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/** A package's own MCP server, as an audience of the OAuth server. */
class OAuthAudiencesTestNotes extends OAuthAudience
{
    public function key(): string
    {
        return 'notes';
    }

    public function scopes(): array
    {
        return ['notes:read', 'notes:write'];
    }

    public function describe(): array
    {
        return [
            'notes:read' => ['label' => 'See notes', 'description' => 'Every note.'],
            'notes:write' => ['label' => 'Change notes', 'description' => 'Write notes, as you.'],
        ];
    }

    public function normalise(string|array|null $requested): array
    {
        $list = is_array($requested) ? $requested : (preg_split('/\s+/', trim((string) $requested)) ?: []);

        return array_values(array_filter($this->scopes(), fn (string $scope) => $scope === 'notes:read' || in_array($scope, $list, true)));
    }

    public function claims(mixed $resource): bool
    {
        return $resource === OAuth::issuer().'/notes/mcp';
    }

    public function consent(Request $request, User $user, mixed $resource): OAuthConsent|Response
    {
        if ($user->name === 'Stranger') {
            return OAuthPage::problem('Notes are only for the team.');
        }
        $requested = $this->normalise((string) $request->query('scope'));

        return new OAuthConsent(['scopes' => $requested], ['notes' => true, 'requested' => $requested, 'scopes' => $this->describe(), 'account' => $user->email]);
    }

    public function approve(User $user, array $pending, array $choices): OAuthGrant|Response
    {
        $scopes = ['notes:read'];
        if (($choices['write'] ?? false) && in_array('notes:write', $pending['scopes'], true)) {
            $scopes[] = 'notes:write';
        }

        return new OAuthGrant($scopes, OAuth::issuer().'/notes/mcp');
    }
}

/** @return array{0: McpOAuthClient, 1: string, 2: string} a client, a PKCE verifier and the authorize URL for it */
function audienceRequest(string $resource, string $scope): array
{
    $client = McpOAuthClient::query()->where('client_id', test()->postJson('/oauth/register', [
        'client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:54321/callback'],
    ])->assertCreated()->json('client_id'))->sole();
    $verifier = Str::random(64);

    return [$client, $verifier, '/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'http://localhost:54321/callback',
        'code_challenge' => OAuthTokenController::challenge($verifier), 'code_challenge_method' => 'S256',
        'state' => 'abc', 'scope' => $scope, 'resource' => $resource,
    ])];
}

test('without a package’s audiences, the OAuth server only knows a workspace’s MCP server', function () {
    // As an install without the packages has it.
    app()->instance(Extensions::class, new Extensions);

    expect(array_keys(app(Extensions::class)->oauthAudiences()))->toBe([McpToken::WORKSPACE])
        ->and(app(Extensions::class)->oauthAudience(McpToken::WORKSPACE))->toBeInstanceOf(WorkspaceAudience::class)
        ->and(app(Extensions::class)->oauthAudience('board'))->toBeNull();
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('scopes_supported', Scopes::ALL);

    // Any other MCP server URL on our domain isn't one of ours.
    [, , $url] = audienceRequest(OAuth::issuer().'/admin/board/mcp', 'board:read board:write');
    $location = $this->actingAs(User::factory()->admin()->create())->get($url)->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('http://localhost:54321/callback?error=invalid_target');

    // A token stored for an audience nothing installed knows can't be refreshed.
    $user = User::factory()->create();
    $client = McpOAuthClient::query()->where('client_id', $this->postJson('/oauth/register', ['client_name' => 'App', 'redirect_uris' => ['https://claude.ai/cb']])->json('client_id'))->sole();
    McpToken::query()->create([
        'audience' => 'board', 'user_id' => $user->id, 'mcp_oauth_client_id' => $client->id, 'kind' => 'oauth', 'name' => 'App',
        'scopes' => ['board:read'], 'token_hash' => McpToken::hash('soa_x'), 'token_prefix' => 'soa_x', 'refresh_hash' => McpToken::hash('sor_old'),
        'expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30),
    ]);
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'sor_old', 'client_id' => $client->client_id])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('a package’s audience gets its own consent page, codes, tokens and scopes', function () {
    app()->instance(Extensions::class, $extensions = new Extensions);
    $extensions->addOAuthAudience(new OAuthAudiencesTestNotes);
    $user = User::factory()->create();

    // Listed after the workspace's, in the order added.
    expect(array_keys($extensions->oauthAudiences()))->toBe([McpToken::WORKSPACE, 'notes']);
    $this->getJson('/.well-known/oauth-authorization-server')->assertJsonPath('scopes_supported', [...Scopes::ALL, 'notes:read', 'notes:write']);

    [$client, $verifier, $url] = audienceRequest(OAuth::issuer().'/notes/mcp', 'notes:read notes:write workspace:write');
    $this->actingAs(User::factory()->create(['name' => 'Stranger']))->get($url)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(fn (Assert $page) => $page->where('problem', 'Notes are only for the team.'));

    $nonce = null;
    $this->actingAs($user)->get($url)->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertInertia(function (Assert $page) use (&$nonce) {
        $page->component('auth/OAuthAuthorize')
            ->where('notes', true)
            ->where('requested', ['notes:read', 'notes:write'])
            ->where('client.local', true)
            ->missing('fixedWorkspace');
        $nonce = $page->toArray()['props']['request'];
    });
    $location = $this->actingAs($user)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'write' => true])->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    $tokens = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $params['code'], 'client_id' => $client->client_id,
        'redirect_uri' => 'http://localhost:54321/callback', 'code_verifier' => $verifier, 'resource' => OAuth::issuer().'/notes/mcp',
    ])->assertOk()->assertJsonPath('scope', 'notes:read notes:write')->json();
    $token = McpToken::query()->sole();
    expect($token->audience)->toBe('notes')->and($token->workspace_id)->toBeNull();

    // A refresh can ask for less, in the audience's own scopes.
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id, 'scope' => 'notes:read workspace:write'])
        ->assertOk()->assertJsonPath('scope', 'notes:read');
});

test('a package can’t take the workspace audience’s key', function () {
    (new Extensions)->addOAuthAudience(new class extends OAuthAudiencesTestNotes
    {
        public function key(): string
        {
            return McpToken::WORKSPACE;
        }
    });
})->throws(InvalidArgumentException::class);
