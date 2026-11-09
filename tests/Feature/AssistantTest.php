<?php

use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Models\DocPage;
use App\Models\DocSection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Docs\DocsSearch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function assistantDocs(): void
{
    $guides = DocSection::query()->create(['title' => 'Guides', 'slug' => 'guides', 'position' => 0]);
    DocPage::query()->create(['doc_section_id' => $guides->id, 'group' => 'Deploying', 'title' => 'Domains and HTTPS', 'slug' => 'domains-and-https',
        'description' => 'Point a domain at your machine.', 'body' => 'Add an A record pointing the domain at your machine.', 'position' => 0, 'published' => true]);
    DocPage::query()->create(['doc_section_id' => $guides->id, 'group' => 'Deploying', 'title' => 'Databases', 'slug' => 'databases',
        'description' => 'Run a database.', 'body' => 'Apps connect through 172.17.0.1.', 'position' => 1, 'published' => true]);
    DocPage::query()->create(['doc_section_id' => $guides->id, 'group' => 'Deploying', 'title' => 'Secret draft', 'slug' => 'draft',
        'description' => null, 'body' => 'domain domain domain', 'position' => 2, 'published' => false]);
}

/** @return list<array<string, mixed>> the NDJSON events a streamed answer carried */
function assistantEvents(string $body): array
{
    return array_values(array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", $body))));
}

/** A Groq chat completion: words, or tool calls. */
function groqTurn(string $content = '', array $calls = []): array
{
    return ['model' => 'openai/gpt-oss-120b', 'choices' => [['message' => array_filter([
        'role' => 'assistant',
        'content' => $content,
        'tool_calls' => array_map(fn (array $c, int $i) => ['id' => 'call_'.$c[0].$i, 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode((object) $c[1])]], $calls, array_keys($calls)) ?: null,
    ])]]];
}

test('Ask AI answers from matching docs, with its tools, and streams what it does', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    assistantDocs();
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqTurn(calls: [['list_machines', []]]))
        ->push(groqTurn('Add an A record.'))]);

    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $response = $this->actingAs($viewer)->post('https://'.$workspace->host().'/assistant', [
        'message' => 'How do I point my domain at an app?',
        'path' => '/machines',
    ]);

    $response->assertOk();
    $events = assistantEvents($response->streamedContent());
    $types = array_column($events, 'type');
    expect($types)->toBe(['chat', 'step', 'result', 'text', 'done'])
        ->and($events[1]['tool'])->toBe('list_machines')
        ->and($events[2]['ok'])->toBeTrue()
        ->and($events[3]['text'])->toBe('Add an A record.')
        ->and($events[4]['allowance'])->toMatchArray(['used' => 1, 'limit' => 30, 'ownKey' => false]);

    Http::assertSent(function (Request $request) {
        $system = $request['messages'][0]['content'];
        $tools = array_column(array_column($request['tools'], 'function'), 'name');

        return $request['messages'][0]['role'] === 'system'
            && str_contains($system, 'Add an A record pointing the domain')
            && str_contains($system, '/guides/domains-and-https')
            && ! str_contains($system, 'Secret draft')
            && in_array('list_templates', $tools, true)
            && in_array('load_tools', $tools, true)
            // Viewers can't change anything, so changes aren't offered.
            && ! str_contains($system, 'create_machine_group')
            && $request['messages'][1]['content'] === 'How do I point my domain at an app?';
    });
});

test('a change Ask AI wants to make waits for approval, then runs', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqTurn(calls: [['load_tools', ['names' => ['create_machine_group']]]]))
        ->push(groqTurn('Making the group.', [['create_machine_group', ['name' => 'Web']]]))
        ->push(groqTurn('Done: the Web group is ready.'))]);

    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    $url = 'https://'.$workspace->host().'/assistant';

    $events = assistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Make a group called Web'])->streamedContent());
    $approval = collect($events)->firstWhere('type', 'approval');
    expect($approval)->not->toBeNull()
        ->and($approval['tool'])->toBe('create_machine_group')
        ->and($approval['fields'][0])->toBe(['label' => 'Name', 'value' => 'Web'])
        ->and($workspace->machineGroups()->count())->toBe(0);

    $chat = collect($events)->firstWhere('type', 'chat')['id'];
    $events = assistantEvents($this->actingAs($workspace->owner)->post("{$url}/decide", ['chat' => $chat, 'call' => $approval['id'], 'approve' => true])->streamedContent());

    expect(array_column($events, 'type'))->toBe(['chat', 'running', 'result', 'text', 'done'])
        ->and($events[2]['ok'])->toBeTrue()
        ->and($workspace->machineGroups()->pluck('name')->all())->toBe(['Web']);

    // Approving it again (another tab, a retry) finds nothing waiting, and runs nothing.
    $again = assistantEvents($this->actingAs($workspace->owner)->post("{$url}/decide", ['chat' => $chat, 'call' => $approval['id'], 'approve' => true])->streamedContent());
    expect(collect($again)->firstWhere('type', 'error')['message'])->toContain('isn’t waiting any more')
        ->and($workspace->machineGroups()->count())->toBe(1);

    // Someone else can't approve it.
    $this->actingAs(User::factory()->create())->post("{$url}/decide", ['chat' => $chat, 'call' => $approval['id'], 'approve' => true])->assertStatus(403);
});

test('Approve all covers later changes in the chat, but never changes to the machine itself', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqTurn(calls: [['load_tools', ['names' => ['create_machine_group', 'install_packages']]]]))
        ->push(groqTurn(calls: [['create_machine_group', ['name' => 'Web']]]))
        ->push(groqTurn(calls: [['create_machine_group', ['name' => 'Db']]]))
        ->push(groqTurn(calls: [['install_packages', ['machine' => 'web-1', 'packages' => ['git'], 'confirm' => 'web-1']]]))]);

    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    $url = 'https://'.$workspace->host().'/assistant';

    $events = assistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Groups, then git'])->streamedContent());
    $approval = collect($events)->firstWhere('type', 'approval');
    expect($approval['alwaysAsk'])->toBeFalse();

    $events = assistantEvents($this->actingAs($workspace->owner)->post("{$url}/decide", [
        'chat' => collect($events)->firstWhere('type', 'chat')['id'], 'call' => $approval['id'], 'approve' => true, 'always' => true,
    ])->streamedContent());

    // The second group went ahead on its own; installing git still asks.
    $asked = collect($events)->where('type', 'approval')->values();
    expect($workspace->machineGroups()->pluck('name')->sort()->values()->all())->toBe(['Db', 'Web'])
        ->and($asked)->toHaveCount(1)
        ->and($asked[0]['tool'])->toBe('install_packages')
        ->and($asked[0]['alwaysAsk'])->toBeTrue();
});

test('a call to a tool it has not loaded yet runs anyway, instead of Groq’s 400', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['error' => [
            'message' => "Tool call validation failed: tool call validation failed: attempted to call tool 'list_machine_groups' which was not in request.tools",
            'type' => 'invalid_request_error',
            'code' => 'tool_use_failed',
            'failed_generation' => '{"name": "list_machine_groups", "arguments": {}}',
        ]], 400)
        ->push(groqTurn('You have no groups yet.'))]);

    $workspace = Workspace::factory()->create();
    $events = assistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Any groups?'])->streamedContent());

    expect(array_column($events, 'type'))->toBe(['chat', 'step', 'result', 'text', 'done'])
        ->and($events[1]['tool'])->toBe('list_machine_groups')
        ->and($events[2]['ok'])->toBeTrue();
    // The next turn offered it, with its result.
    Http::assertSent(fn (Request $request) => in_array('list_machine_groups', array_column(array_column($request['tools'] ?? [], 'function'), 'name'), true));
});

test('saying it started a change without making one gets it told once to make it', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqTurn('I’ll create the Web group now. Deploy started, you can watch it.'))
        ->push(groqTurn(calls: [['create_machine_group', ['name' => 'Web']]]))]);

    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    $events = assistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Make a Web group'])->streamedContent());

    expect(collect($events)->firstWhere('type', 'approval')['tool'])->toBe('create_machine_group');
    Http::assertSent(fn (Request $request) => str_contains((string) end($request->data()['messages'])['content'], 'you described a change but didn’t make it'));
});

test('a stray thought from the model instead of an answer is asked again, not shown as an error', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'Parsing failed.', 'type' => 'invalid_request_error', 'code' => 'output_parse_failed', 'failed_generation' => 'We need to list services to see name.']], 400)
        ->push(groqTurn('You have two machines.'))]);

    $workspace = Workspace::factory()->create();
    $events = assistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Machines?'])->streamedContent());

    expect(array_column($events, 'type'))->toBe(['chat', 'text', 'done']);
    Http::assertSentCount(2);
});

test('a call Groq refuses over the letter of the schema still runs, so the tool can say what’s really wrong', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['error' => [
            'message' => 'Tool call validation failed: tool call validation failed: parameters for tool list_machines did not match schema: errors: [`/env`: expected object, but got array]',
            'code' => 'tool_use_failed',
            'failed_generation' => '{"name": "list_machines", "arguments": {}}',
        ]], 400)
        ->push(groqTurn('None yet.'))]);

    $workspace = Workspace::factory()->create();
    $events = assistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Machines?'])->streamedContent());

    expect(array_column($events, 'type'))->toBe(['chat', 'step', 'result', 'text', 'done'])
        ->and($events[1]['tool'])->toBe('list_machines');
});

test('naming a tool as the next step instead of calling it, or asking leave in words, gets it told once to go on', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqTurn('Now list them. Use list_machines to see them.'))
        ->push(groqTurn(calls: [['list_machines', []]]))
        ->push(groqTurn('There are none. Let me know if you’d like me to proceed with connecting one.'))
        ->push(groqTurn('Connect one from Machines › Connect machine.'))]);

    $workspace = Workspace::factory()->create();
    $events = assistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Show my machines'])->streamedContent());

    // Told once about the tool it named; the second nudge isn't sent, so its question stands.
    expect(collect($events)->where('type', 'step')->pluck('tool')->all())->toBe(['list_machines'])
        ->and(collect($events)->where('type', 'text')->pluck('text')->last())->toContain('Let me know');
    Http::assertSentCount(3);
});

test('with its own key a workspace picks the model in the chat, from the provider’s own list', function () {
    $workspace = Workspace::factory()->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-theirs', 'hint' => '…eirs', 'model' => 'claude-sonnet-5-5']);
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => [
            ['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-09-01T00:00:00Z'],
            ['id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5', 'created_at' => '2026-08-01T00:00:00Z'],
        ]]),
        'api.anthropic.com/v1/messages' => Http::response(['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => 'Hi.']]]),
    ]);
    $url = 'https://'.$workspace->host().'/assistant';

    $options = $this->actingAs($workspace->owner)->getJson("{$url}/models")->assertOk()->json('options');
    expect($options)->toHaveCount(1)
        ->and($options[0])->toMatchArray(['provider' => 'anthropic', 'default' => 'claude-sonnet-5-5'])
        ->and(array_column($options[0]['models'], 'id'))->toBe(['claude-opus-5-5', 'claude-sonnet-5-5']);

    $events = assistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Hi', 'model' => 'anthropic:claude-opus-5-5'])->streamedContent());
    expect($events[0]['model'])->toBe('anthropic:claude-opus-5-5');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/messages') && $r['model'] === 'claude-opus-5-5');

    // A provider it has no key for is ignored: its own key's model answers.
    $events = assistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Hi', 'model' => 'openai:gpt-5'])->streamedContent());
    expect($events[0]['model'])->toBe('anthropic:claude-sonnet-5-5');
    $this->actingAs($workspace->owner)->postJson($url, ['message' => 'Hi', 'model' => 'groq:anything'])->assertUnprocessable();
});

test('without its own key there’s nothing to pick, and a failed model list falls back to the current models', function () {
    $workspace = Workspace::factory()->create();
    $url = 'https://'.$workspace->host().'/assistant/models';
    expect($this->actingAs($workspace->owner)->getJson($url)->json('options'))->toBe([]);

    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-theirs', 'hint' => '…eirs']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
    $options = $this->actingAs($workspace->owner)->getJson($url)->json('options');
    expect($options[0]['default'])->toBe('gpt-5')
        ->and(array_column($options[0]['models'], 'id'))->toContain('gpt-5');
});

test('Ask AI reads and changes but never runs commands, and secrets in arguments are hidden at any depth', function () {
    $workspace = Workspace::factory()->create();
    $context = new McpContext($workspace, $workspace->owner, via: 'assistant', grantedScopes: [Scopes::READ, Scopes::WRITE]);

    expect($context->allows(Scopes::WRITE))->toBeTrue()
        ->and($context->allows(Scopes::COMMANDS))->toBeFalse()
        ->and(app(ToolRegistry::class)->denial(app(ToolRegistry::class)->find('run_command'), $context))->not->toBeNull();

    expect(McpServer::redact(['app' => 'shop', 'set' => ['DB_PASSWORD' => 'x'], 'vars' => ['DB_HOST' => 'h', 'DB_PASSWORD' => 'x', 'GITHUB_TOKEN' => 't']]))
        ->toBe(['app' => 'shop', 'set' => '[hidden]', 'vars' => ['DB_HOST' => 'h', 'DB_PASSWORD' => '[hidden]', 'GITHUB_TOKEN' => '[hidden]']]);
});

test('Ask AI says so when it is not set up, or is out of messages', function () {
    $workspace = Workspace::factory()->create();
    $url = 'https://'.$workspace->host().'/assistant';

    config()->set('services.groq.key', null);
    $events = assistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Hi'])->streamedContent());
    expect(collect($events)->firstWhere('type', 'error')['message'])->toContain('isn’t set up');

    config()->set('services.groq.key', 'test-key');
    foreach (range(1, 30) as $i) {
        RateLimiter::hit("assistant:hour:{$workspace->id}", 3600);
    }
    $this->actingAs($workspace->owner)->postJson($url, ['message' => 'Hi'])
        ->assertStatus(429)->assertJsonPath('allowance.limit', 30);

    $this->actingAs($workspace->owner)->postJson($url, ['message' => ''])->assertUnprocessable();
});

test('docs search ranks title matches first and skips drafts', function () {
    assistantDocs();

    expect(app(DocsSearch::class)->search('connect to my database')->pluck('slug')->all())->toBe(['databases'])
        ->and(app(DocsSearch::class)->search('domain')->pluck('slug')->all())->toBe(['domains-and-https'])
        ->and(app(DocsSearch::class)->search('the and for'))->toBeEmpty();
});
