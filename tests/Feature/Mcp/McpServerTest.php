<?php

use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\User;
use App\Models\Workspace;

test('without a token the server says how to sign in', function () {
    $workspace = Workspace::factory()->create();

    $this->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="https://'.$workspace->host().'/.well-known/oauth-protected-resource/mcp"');

    $this->withHeaders(['Authorization' => 'Bearer nope'])->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

test('it initializes and answers pings without a session', function () {
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    mcpRequest($workspace, $key, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'serveros')
        ->assertJsonPath('result.capabilities.tools.listChanged', false)
        ->assertJson(fn ($json) => $json->where('result.instructions', fn ($text) => str_contains($text, 'EXPERIMENTAL'))->etc());

    // An unknown version gets ours instead.
    mcpRequest($workspace, $key, 'initialize', ['protocolVersion' => '1999-01-01'])->assertJsonPath('result.protocolVersion', McpServer::VERSIONS[0]);

    mcpRequest($workspace, $key, 'ping')->assertOk()->assertJsonPath('jsonrpc', '2.0');

    // Notifications get no answer.
    $this->withHeaders(['Authorization' => "Bearer {$key}"])
        ->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        ->assertStatus(202);

    mcpRequest($workspace, $key, 'no/such')->assertJsonPath('error.code', -32601);
    $this->call('POST', 'https://'.$workspace->host().'/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$key}"], '{nope')
        ->assertStatus(400)->assertJsonPath('error.code', -32700);
    $this->withHeaders(['Authorization' => "Bearer {$key}"])->get('https://'.$workspace->host().'/mcp')->assertStatus(405);
});

test('a key only works for its own workspace, and not once revoked', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $key = mcpKey($workspace);

    mcpRequest($other, $key, 'ping')->assertUnauthorized();
    mcpRequest($workspace, $key, 'ping')->assertOk();

    McpToken::query()->sole()->revoke();
    mcpRequest($workspace, $key, 'ping')->assertUnauthorized();
});

test('it acts as the person with their role as it is now', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $key = mcpKey($workspace, $viewer);

    $result = mcpCall($workspace, $key, 'get_workspace');
    expect($result['error'])->toBeFalse()
        ->and($result['data']['you']['role'])->toBe('Viewer')
        ->and($result['data']['you']['can'])->toBe([]);

    $workspace->members()->detach($viewer);
    mcpRequest($workspace, $key, 'ping')->assertForbidden();

    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->forceFill(['suspended_at' => now()])->save();
    mcpRequest($workspace, $key, 'ping')->assertForbidden();
});

test('read-only connections only see read tools, and calls are logged with secrets hidden', function () {
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace, scopes: [Scopes::READ]);

    $tools = collect(mcpRequest($workspace, $key, 'tools/list')->assertOk()->json('result.tools'));
    expect($tools->pluck('name'))->toContain('get_workspace')
        ->and($tools->every(fn ($t) => $t['annotations']['readOnlyHint'] === true))->toBeTrue()
        ->and($tools->first()['inputSchema']['type'])->toBe('object');

    mcpCall($workspace, $key, 'get_workspace');
    mcpRequest($workspace, $key, 'tools/call', ['name' => 'nope'])->assertJsonPath('error.code', -32602);

    $call = McpToolCall::query()->sole();
    expect($call->tool)->toBe('get_workspace')->and($call->ok)->toBeTrue()->and($call->user_id)->toBe($workspace->owner_id);

    expect(McpServer::redact(['env' => ['A' => 'secret'], 'name' => 'web', 'nested' => ['password' => 'x']]))
        ->toBe(['env' => '[hidden]', 'name' => 'web', 'nested' => ['password' => '[hidden]']]);
});

test('each connection is rate limited', function () {
    config()->set('mcp.rate_limit', 3);
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    foreach (range(1, 3) as $i) {
        mcpRequest($workspace, $key, 'ping')->assertOk();
    }
    mcpRequest($workspace, $key, 'ping')->assertStatus(429)->assertHeader('Retry-After');
});

test('workspaces that require two-factor need it for MCP too', function () {
    $workspace = Workspace::factory()->create(['require_two_factor' => true]);
    $key = mcpKey($workspace);

    mcpRequest($workspace, $key, 'ping')->assertForbidden();
});

test('changes made through MCP are credited to the person, via MCP', function () {
    $workspace = Workspace::factory()->create();
    $context = new McpContext($workspace, $workspace->owner, McpToken::issueKey($workspace, $workspace->owner, 'Laptop', Scopes::ALL)[0]);

    $context->record('service.rename', 'Shop');

    expect(Activity::query()->sole())
        ->user_id->toBe($workspace->owner_id)
        ->meta->toBe(['via' => 'mcp', 'client' => 'Laptop']);
});

test('browsers can reach it: CORS preflight', function () {
    $workspace = Workspace::factory()->create();

    $this->call('OPTIONS', 'https://'.$workspace->host().'/mcp')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});
