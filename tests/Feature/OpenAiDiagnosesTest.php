<?php

use App\Jobs\DiagnoseFix;
use App\Models\AgentFix;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Fixes\FixDiagnoser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->ai = ['provider' => 'openai', 'key' => 'sk-test', 'model' => 'gpt-6.1-sol', 'own' => true];
    $this->answer = ['title' => 'The shop stopped', 'steps' => []];
    $this->response = [
        'status' => 'completed',
        'model' => 'gpt-6.1-sol',
        'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($this->answer)],
            ]],
        ],
    ];
});

test('OpenAI diagnoses use Responses JSON mode and read typed message output', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response($this->response)]);

    expect(app(AiProviders::class)->json($this->ai, 'Return JSON from the evidence.', 'The shop exited.', 2000))
        ->toBe([$this->answer, 'gpt-6.1-sol']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'gpt-6.1-sol'
        && $request['instructions'] === 'Return JSON from the evidence.'
        && $request['input'] === [['role' => 'user', 'content' => 'The shop exited.']]
        && $request['text'] === ['format' => ['type' => 'json_object']]
        && $request['max_output_tokens'] === 4000
        && $request['store'] === false
        && ! isset($request['messages']) && ! isset($request['response_format']));
    Http::assertSentCount(1);
});

test('incomplete OpenAI diagnoses cannot propose actions even when their JSON is valid', function () {
    $workspace = Workspace::factory()->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-test', 'hint' => 'test', 'model' => 'gpt-6.1-sol']);
    $machine = Machine::factory()->for($workspace)->create(['inventory' => ['services' => [
        ['key' => 'docker:shop', 'name' => 'shop', 'manager' => 'docker', 'status' => 'exited', 'details' => ['managed' => 'true']],
    ]]]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);
    $response = $this->response;
    $response['status'] = 'incomplete';
    $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
    $response['output'][1]['content'][0]['text'] = json_encode(['title' => 'Restart shop', 'steps' => [['type' => 'restart', 'service' => 'docker:shop', 'why' => 'Bring it back.']]]);
    Http::fake(['api.openai.com/v1/responses' => Http::response($response)]);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->state)->toBe('error')
        ->and($fix->error)->toBe('The model didn’t finish its answer. Try again.')
        ->and($fix->actions)->toBeNull()
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('OpenAI diagnoses require a completed response', function (?string $status) {
    $response = $this->response;
    $response['status'] = $status;
    Http::fake(['api.openai.com/v1/responses' => Http::response($response)]);

    expect(fn () => app(AiProviders::class)->json($this->ai, 'Return JSON.', 'Evidence'))
        ->toThrow(RuntimeException::class, 'didn’t finish');
})->with(['failed', 'in_progress', null]);

test('OpenAI diagnoses reject refusals and unfinished messages', function (string $kind) {
    $response = $this->response;
    if ($kind === 'refusal') {
        $response['output'][1]['content'][] = ['type' => 'refusal', 'refusal' => 'Cannot help.'];
    } elseif ($kind === 'incomplete message') {
        $response['output'][1]['status'] = 'incomplete';
    } else {
        unset($response['output'][1]['status']);
    }
    Http::fake(['api.openai.com/v1/responses' => Http::response($response)]);

    expect(fn () => app(AiProviders::class)->json($this->ai, 'Return JSON.', 'Evidence'))
        ->toThrow(RuntimeException::class, $kind === 'refusal' ? 'declined to answer' : 'didn’t finish');
})->with(['refusal', 'incomplete message', 'missing message status']);

test('OpenAI diagnoses require a complete JSON object', function (string $text) {
    $response = $this->response;
    $response['output'][1]['content'][0]['text'] = $text;
    Http::fake(['api.openai.com/v1/responses' => Http::response($response)]);

    expect(fn () => app(AiProviders::class)->json($this->ai, 'Return JSON.', 'Evidence'))
        ->toThrow(RuntimeException::class, 'wasn’t in the shape');
})->with(['empty' => '', 'truncated' => '{"steps": [', 'extra text' => 'Here is JSON: {"steps": []}', 'array' => '[{"steps": []}]']);

test('OpenAI diagnosis HTTP failures keep their useful errors', function (int $status, string $message) {
    Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Unsupported model.']], $status)]);

    expect(fn () => app(AiProviders::class)->json($this->ai, 'Return JSON.', 'Evidence'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    [401, 'Your OpenAI key was refused.'],
    [404, 'That model isn’t available to your OpenAI key: gpt-6.1-sol.'],
    [429, 'Your OpenAI key is out of requests for now.'],
    [400, 'Your OpenAI key didn’t answer (400): Unsupported model.'],
]);

test('diagnoses keep the Anthropic and Groq transports unchanged', function (string $provider) {
    $ai = [...$this->ai, 'provider' => $provider, 'model' => 'existing-model'];
    $body = $provider === 'anthropic'
        ? ['content' => [['type' => 'text', 'text' => json_encode($this->answer)]]]
        : ['choices' => [['message' => ['content' => json_encode($this->answer)]]]];
    $url = $provider === 'anthropic' ? 'https://api.anthropic.com/v1/messages' : 'https://api.groq.com/openai/v1/chat/completions';
    Http::fake([$url => Http::response($body)]);

    expect(app(AiProviders::class)->json($ai, 'Return JSON.', 'Evidence'))->toBe([$this->answer, 'existing-model']);
    Http::assertSent(fn (Request $request) => $request->url() === $url
        && $request['messages'][0]['content'] === ($provider === 'anthropic' ? 'Evidence' : 'Return JSON.')
        && ! isset($request['store']) && ! isset($request['text']));
})->with(['anthropic', 'groq']);
