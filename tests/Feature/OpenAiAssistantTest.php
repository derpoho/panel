<?php

use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Assistant\AgentChat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function openAiAssistantResponse(array $output, string $model = 'gpt-6.1-sol'): array
{
    return ['id' => 'resp_test', 'status' => 'completed', 'model' => $model, 'output' => $output];
}

function openAiAssistantText(string $text): array
{
    return ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
}

function openAiAssistantCall(string $id, string $name, array $arguments = []): array
{
    return ['id' => 'fc_'.$id, 'type' => 'function_call', 'status' => 'completed', 'call_id' => $id, 'name' => $name, 'arguments' => json_encode((object) $arguments)];
}

function openAiAssistantEvents(string $body): array
{
    return array_values(array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", $body))));
}

test('OpenAI models use the Responses function calling contract', function (string $model) {
    $message = openAiAssistantText('There are ');
    $message['content'][] = ['type' => 'output_text', 'text' => 'no machines.', 'annotations' => []];
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiAssistantResponse([
        ['type' => 'reasoning', 'id' => 'rs_test', 'summary' => [], 'encrypted_content' => 'opaque'],
        $message,
    ], $model))]);

    $answer = app(AgentChat::class)->turn(
        ['provider' => 'openai', 'key' => 'sk-test', 'model' => $model, 'own' => true],
        'Read the machines.', [['role' => 'user', 'content' => 'What is running?']],
        [['name' => 'list_machines', 'description' => 'List machines.', 'schema' => ['type' => 'object', 'properties' => (object) []]]],
    );

    expect($answer['text'])->toBe('There are no machines.')
        ->and($answer['calls'])->toBe([])
        ->and($answer['model'])->toBe($model);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
        && $r->header('Authorization') === ['Bearer sk-test']
        && $r['model'] === $model && $r['instructions'] === 'Read the machines.'
        && $r['input'] === [['role' => 'user', 'content' => 'What is running?']]
        && $r['store'] === false && in_array('reasoning.encrypted_content', $r['include'], true)
        && $r['max_output_tokens'] > 0
        && $r['tools'][0]['name'] === 'list_machines' && $r['tools'][0]['strict'] === false
        && ! isset($r['messages']) && ! isset($r['max_completion_tokens'])
        && ! isset($r['temperature']) && ! isset($r['reasoning_effort']));
})->with(['gpt-5', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6-sol', 'gpt-6-sol', 'gpt-6-luna', 'gpt-6-astra', 'gpt-6.1-sol', 'gpt-6.1-terra']);

test('OpenAI tool results keep the original call id and encrypted reasoning across chat turns', function () {
    $workspace = Workspace::factory()->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-test', 'hint' => '…test', 'model' => 'gpt-6.1-sol']);
    $reasoning = ['type' => 'reasoning', 'id' => 'rs_private', 'summary' => [], 'encrypted_content' => 'private-ciphertext'];
    $call = openAiAssistantCall('call_machines', 'list_machines');
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(openAiAssistantResponse([$reasoning, $call]))
        ->push(openAiAssistantResponse([openAiAssistantText('There are no machines.')]))]);

    $response = $this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'List my machines.']);
    $response->assertOk();
    $body = $response->streamedContent();
    $events = openAiAssistantEvents($body);
    expect(array_column($events, 'type'))->toBe(['chat', 'step', 'result', 'text', 'done'])
        ->and($events[1]['id'])->toBe('call_machines')
        ->and($events[2]['ok'])->toBeTrue()
        ->and($body)->not->toContain('private-ciphertext', 'rs_private');
    Http::assertSent(function (Request $r) use ($reasoning, $call) {
        $input = $r['input'];
        $result = collect($input)->firstWhere('type', 'function_call_output');

        return in_array($reasoning, $input, true) && in_array($call, $input, true)
            && $result !== null && $result['call_id'] === 'call_machines';
    });
    Http::assertSentCount(2);
});

test('OpenAI resumes a cached tool call only after approval', function (bool $approve) {
    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-test', 'hint' => '…test', 'model' => 'gpt-6.1-sol']);
    $reasoning = ['type' => 'reasoning', 'id' => 'rs_approval', 'summary' => [], 'encrypted_content' => 'approval-ciphertext'];
    $call = openAiAssistantCall('call_create', 'create_machine_group', ['name' => 'Web']);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(openAiAssistantResponse([openAiAssistantCall('call_load', 'load_tools', ['names' => ['create_machine_group']])]))
        ->push(openAiAssistantResponse([$reasoning, $call]))
        ->push(openAiAssistantResponse([openAiAssistantText($approve ? 'The Web group is ready.' : 'The change was declined.')]))]);
    $url = 'https://'.$workspace->host().'/assistant';

    $events = openAiAssistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Create a group called Web.'])->streamedContent());
    $approval = collect($events)->firstWhere('type', 'approval');
    expect($approval['id'])->toBe('call_create')->and($workspace->machineGroups()->count())->toBe(0);
    Http::assertSentCount(2);
    $chat = collect($events)->firstWhere('type', 'chat')['id'];
    $events = openAiAssistantEvents($this->post("{$url}/decide", ['chat' => $chat, 'call' => $approval['id'], 'approve' => $approve])->streamedContent());

    expect(collect($events)->firstWhere('type', 'error'))->toBeNull()
        ->and($workspace->machineGroups()->count())->toBe($approve ? 1 : 0);
    Http::assertSent(function (Request $r) use ($reasoning, $call, $approve) {
        $result = collect($r['input'])->first(fn (array $item) => ($item['type'] ?? null) === 'function_call_output' && $item['call_id'] === 'call_create');

        return in_array($reasoning, $r['input'], true) && in_array($call, $r['input'], true)
            && $result !== null && ($approve || str_contains($result['output'], 'declined'));
    });
    Http::assertSentCount(3);
})->with([true, false]);

test('a new OpenAI chat message resolves the pending call without running it', function () {
    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-test', 'hint' => '…test', 'model' => 'gpt-6.1-sol']);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(openAiAssistantResponse([openAiAssistantCall('call_load', 'load_tools', ['names' => ['create_machine_group']])]))
        ->push(openAiAssistantResponse([openAiAssistantCall('call_pending', 'create_machine_group', ['name' => 'Web'])]))
        ->push(openAiAssistantResponse([openAiAssistantText('The change was skipped.')]))]);
    $url = 'https://'.$workspace->host().'/assistant';
    $events = openAiAssistantEvents($this->actingAs($workspace->owner)->post($url, ['message' => 'Create a group called Web.'])->streamedContent());
    $chat = collect($events)->firstWhere('type', 'chat')['id'];

    $events = openAiAssistantEvents($this->post($url, ['chat' => $chat, 'message' => 'Skip that.'])->streamedContent());

    expect(collect($events)->firstWhere('type', 'error'))->toBeNull()
        ->and(collect($events)->firstWhere('type', 'result')['id'])->toBe('call_pending')
        ->and($workspace->machineGroups()->count())->toBe(0);
    Http::assertSent(function (Request $r) {
        $result = collect($r['input'])->first(fn (array $item) => ($item['type'] ?? null) === 'function_call_output' && $item['call_id'] === 'call_pending');

        return $result !== null && str_contains($result['output'], 'new message instead of approving');
    });
    Http::assertSentCount(3);
});

test('OpenAI never executes calls from incomplete failed or refused responses', function (array $response) {
    $workspace = Workspace::factory()->create(['mcp_call_limit_override' => -1]);
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-test', 'hint' => '…test', 'model' => 'gpt-6.1-sol']);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::response($response)]);

    $events = openAiAssistantEvents($this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', ['message' => 'Create a group.'])->streamedContent());
    expect(array_column($events, 'type'))->toBe(['chat', 'error', 'done'])
        ->and($workspace->machineGroups()->count())->toBe(0);
    Http::assertSentCount(1);
})->with([
    'incomplete' => fn () => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [openAiAssistantCall('call_partial', 'create_machine_group', ['name' => 'Web'])]]],
    'failed' => fn () => [['status' => 'failed', 'error' => ['message' => 'Generation failed.'], 'output' => []]],
    'refused' => fn () => [openAiAssistantResponse([['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot do this.']]], openAiAssistantCall('call_refused', 'create_machine_group', ['name' => 'Web'])])],
    'invalid arguments' => fn () => [openAiAssistantResponse([array_replace(openAiAssistantCall('call_invalid', 'create_machine_group'), ['arguments' => '{"name":'])])],
    'incomplete call' => fn () => [openAiAssistantResponse([array_replace(openAiAssistantCall('call_partial', 'create_machine_group', ['name' => 'Web']), ['status' => 'in_progress'])])],
    'incomplete message' => fn () => [openAiAssistantResponse([array_replace(openAiAssistantText('Partial answer.'), ['status' => 'incomplete'])])],
]);

test('switching OpenAI models rebuilds call history without replaying another model reasoning', function () {
    $call = openAiAssistantCall('call_old', 'list_machines');
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiAssistantResponse([openAiAssistantText('No machines.')], 'gpt-5.4'))]);

    app(AgentChat::class)->turn(['provider' => 'openai', 'key' => 'sk-test', 'model' => 'gpt-5.4', 'own' => true], 'Read only.', [
        ['role' => 'user', 'content' => 'List machines.'],
        ['role' => 'assistant', 'content' => '', 'calls' => [['id' => 'call_old', 'name' => 'list_machines', 'arguments' => []]], 'openai_model' => 'gpt-6.1-sol', 'openai_output' => [['type' => 'reasoning', 'id' => 'rs_old', 'summary' => [], 'encrypted_content' => 'old-ciphertext'], $call]],
        ['role' => 'tool', 'id' => 'call_old', 'name' => 'list_machines', 'content' => '[]'],
        ['role' => 'user', 'content' => 'How many?'],
    ], []);

    Http::assertSent(function (Request $r) {
        $call = collect($r['input'])->firstWhere('type', 'function_call');
        $result = collect($r['input'])->firstWhere('type', 'function_call_output');

        return ! str_contains($r->body(), 'old-ciphertext') && ! str_contains($r->body(), 'rs_old')
            && $call['call_id'] === 'call_old' && $call['name'] === 'list_machines'
            && $result['call_id'] === 'call_old' && $result['output'] === '[]';
    });
});
