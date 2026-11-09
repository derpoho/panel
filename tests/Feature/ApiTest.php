<?php

use App\Api\ApiRoutes;
use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
});

/**
 * A workspace on a plan with one online machine running a managed
 * container, and an API key for its owner.
 *
 * @param  list<string>  $scopes
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function apiWorkspace(array $scopes = Scopes::ALL, ?Plan $plan = Plan::Pro): array
{
    $workspace = Workspace::factory()->onPlan($plan ?? Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();
    [, $secret] = McpToken::issueKey($workspace, $workspace->owner, 'CI', $scopes, McpToken::API);
    if ($plan === null) {
        $workspace->forceFill(['plan' => null, 'trial_ends_at' => now()->subDay()])->save();
    }

    return [$workspace, $machine, $secret];
}

function api(string $method, string $path, string $key, array $body = []): TestResponse
{
    return test()->withHeader('Authorization', "Bearer {$key}")->json($method, "/api/v1/{$path}", $body);
}

test('every route runs a tool that exists, and every tool has a route', function () {
    $tools = array_keys(app(ToolRegistry::class)->all());
    $routed = collect(ApiRoutes::all())->pluck('tool')->unique()->values()->all();

    expect(array_diff($routed, $tools))->toBe([])
        ->and(array_values(array_diff($tools, $routed)))->toBe([]);
});

test('a key reads the workspace, with rate limit headers', function () {
    [$workspace, $machine, $key] = apiWorkspace();

    api('GET', '', $key)->assertOk()->assertJsonPath('data.workspace.id', $workspace->id)->assertJsonPath('data.key.name', 'CI');

    api('GET', 'machines?limit=5', $key)->assertOk()
        ->assertHeader('RateLimit-Limit', '300')
        ->assertJsonPath('data.machines.0.name', 'web-1');

    api('GET', "machines/{$machine->id}/services?managed=true", $key)->assertOk()
        ->assertJsonPath('data.services.0.key', 'docker:aaa');

    expect(McpToolCall::query()->where('via', 'api')->where('status', 200)->count())->toBe(2);
});

test('changes go through the same checks as MCP, and are credited to the API', function () {
    [$workspace, $machine, $key] = apiWorkspace();

    // Restarting a service starts a job on the machine.
    $restart = api('POST', "machines/{$machine->id}/services/docker:aaa/restart", $key)->assertOk()->json('data');
    expect(json_encode($restart))->toContain('queued');

    // Rebooting needs confirm, as it does over MCP.
    api('POST', "machines/{$machine->id}/reboot", $key)->assertStatus(422)
        ->assertJsonPath('error.type', 'unprocessable');

    api('PATCH', "machines/{$machine->id}", $key, ['name' => 'web-2'])->assertOk();
    expect($machine->fresh()->name)->toBe('web-2')
        ->and(Activity::query()->where('workspace_id', $workspace->id)->get()->pluck('meta.via')->filter()->unique()->values()->all())->toBe(['api']);
});

test('a read-only key can’t change anything, and a member’s role still applies', function () {
    [$workspace, $machine, $key] = apiWorkspace([Scopes::READ]);

    api('PATCH', "machines/{$machine->id}", $key, ['name' => 'nope'])->assertForbidden()->assertJsonPath('error.type', 'forbidden');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    [, $viewerKey] = McpToken::issueKey($workspace, $viewer, 'viewer', Scopes::ALL, McpToken::API);
    api('PATCH', "machines/{$machine->id}", $viewerKey, ['name' => 'nope'])->assertForbidden();

    expect($machine->fresh()->name)->toBe('web-1');
});

test('keys only work for the API, for their own workspace, until revoked', function () {
    [$workspace, $machine, $key] = apiWorkspace();
    [, $mcpKey] = McpToken::issueKey($workspace, $workspace->owner, 'MCP', Scopes::ALL);

    $this->getJson('/api/v1/machines')->assertUnauthorized()->assertJsonPath('error.type', 'unauthenticated');
    api('GET', 'machines', $mcpKey)->assertUnauthorized();

    // The API key can't open the workspace's MCP server either.
    $this->withHeader('Authorization', "Bearer {$key}")
        ->postJson('https://'.$workspace->slug.'.'.config('app.domain').'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();

    McpToken::query()->where('audience', McpToken::API)->sole()->revoke();
    api('GET', 'machines', $key)->assertUnauthorized();
});

test('without a plan the API is off, and keys are rate limited by plan', function () {
    [, , $key] = apiWorkspace(plan: null);
    api('GET', 'machines', $key)->assertStatus(402)->assertJsonPath('error.type', 'plan_required');

    config()->set('api.rate_limits.pro', 2);
    [, , $limited] = apiWorkspace();
    api('GET', 'workspace', $limited)->assertOk();
    api('GET', 'workspace', $limited)->assertOk()->assertHeader('RateLimit-Remaining', '0');
    api('GET', 'workspace', $limited)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.type', 'rate_limited');
});

test('any tool runs by name, unknown routes answer in JSON, and bad bodies are refused', function () {
    [, , $key] = apiWorkspace();

    api('POST', 'tools/list_machines', $key, ['limit' => 1])->assertOk()->assertJsonPath('data.count', 1);
    api('POST', 'tools/no_such_tool', $key)->assertNotFound()->assertJsonPath('error.type', 'not_found');
    api('GET', 'tools', $key)->assertOk()->assertJsonPath('data.0.name', 'get_workspace');

    api('GET', 'nope', $key)->assertNotFound()->assertJsonPath('error.type', 'not_found');
    api('DELETE', 'workspace', $key)->assertStatus(405)->assertJsonPath('error.type', 'method_not_allowed');

    $this->call('POST', '/api/v1/tools/list_machines', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$key}"], '[1, 2]')
        ->assertStatus(400)->assertJsonPath('error.type', 'invalid_json');
});

test('the OpenAPI spec describes every route, and needs no key', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->json();

    $operations = collect($spec['paths'])->flatMap(fn (array $methods) => array_values($methods));
    expect($spec['openapi'])->toBe('3.1.0')
        ->and($operations)->toHaveCount(count(ApiRoutes::all()))
        ->and($operations->pluck('operationId')->duplicates())->toBeEmpty()
        ->and($spec['paths']['/machines/{machine}/services/{service}/restart']['post']['x-scope'])->toBe(Scopes::WRITE)
        ->and(collect($spec['paths']['/machines']['get']['parameters'])->pluck('name'))->toContain('limit');
});

test('settings make and revoke API keys, separately from MCP keys', function () {
    [$workspace] = apiWorkspace();
    $owner = $workspace->owner;
    $url = fn (string $path = '') => 'https://'.$workspace->host().'/settings/api'.$path;

    $made = $this->actingAs($owner)->postJson($url('/keys'), ['name' => 'Deploy bot', 'access' => 'write'])->assertCreated()->json();
    expect($made['secret'])->toStartWith('sos_')
        ->and(McpToken::query()->find($made['id'])->audience)->toBe(McpToken::API);

    $this->actingAs($owner)->get($url())->assertOk()->assertInertia(fn ($page) => $page
        ->component('workspace/settings/Api')
        ->has('keys', 2)
        ->where('baseUrl', rtrim((string) config('app.url'), '/').'/api/v1'));

    // MCP settings don't list API keys.
    $this->actingAs($owner)->get('https://'.$workspace->host().'/settings/mcp')->assertInertia(fn ($page) => $page->has('connections', 0));

    $this->actingAs($owner)->delete($url("/keys/{$made['id']}"))->assertRedirect();
    expect(McpToken::query()->find($made['id'])->revoked_at)->not->toBeNull();

    // A viewer can only make read-only keys.
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson($url('/keys'), ['name' => 'x', 'access' => 'write'])->assertUnprocessable();
    $this->actingAs($viewer)->postJson($url('/keys'), ['name' => 'x', 'access' => 'read'])->assertCreated();
});

test('a provisioning script gets a one-time token for each new server, for as long as it asks', function () {
    config()->set('daemon.install_ready', true);
    config()->set('daemon.install_url', 'https://serveros.test/install.sh');
    config()->set('daemon.panel_url', 'https://serveros.test');
    [$workspace, , $key] = apiWorkspace();

    $data = api('POST', 'machines', $key, ['expiresInMinutes' => 120])->assertOk()->json('data');
    $enrolment = DaemonEnrolment::query()->sole();
    expect($data['token'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($data['command'])->toContain("--token='{$data['token']}'")
        ->and($enrolment->token_hash)->toBe(hash('sha256', $data['token']))
        ->and($enrolment->workspace_id)->toBe($workspace->id)
        ->and($enrolment->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue();

    // No longer than a day.
    api('POST', 'machines', $key, ['expiresInMinutes' => 1441])->assertStatus(422);
});
