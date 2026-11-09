<?php

use App\Support\Assistant\GroqStream;
use App\Support\Sentry\Scrub;
use Illuminate\Support\Facades\Http;
use Sentry\SentrySdk;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Response;

/** A sampled transaction on the hub, as the Sentry middleware starts one. */
function tracedRequest(): Transaction
{
    $transaction = new Transaction(TransactionContext::make()->setName('/ask')->setSampled(true));
    $transaction->initSpanRecorder();
    SentrySdk::getCurrentHub()->setSpan($transaction);

    return $transaction;
}

/** @return array<string, Span> */
function aiSpans(Transaction $transaction): array
{
    return collect($transaction->getSpanRecorder()?->getSpans() ?? [])
        ->filter(fn (Span $span) => str_starts_with((string) $span->getOp(), 'gen_ai.'))
        ->keyBy(fn (Span $span) => (string) $span->getOp())->all();
}

/** Runs a streamed answer, catching what it writes (it flushes as it goes). */
function streamed(Response $response): string
{
    $out = '';
    ob_start(function (string $chunk) use (&$out) {
        $out .= $chunk;

        return '';
    });
    $response->sendContent();
    ob_end_clean();

    return $out;
}

afterEach(fn () => SentrySdk::getCurrentHub()->setSpan(null));

test('an assistant answer is an invoke_agent span with a chat span, tokens and the scrubbed conversation', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::response(
        "data: {\"model\":\"openai/gpt-oss-120b\",\"choices\":[{\"delta\":{\"content\":\"Mail sam@example.com.\"}}]}\n\n".
        "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":120,\"completion_tokens\":8,\"total_tokens\":128}}\n\n".
        "data: [DONE]\n\n",
    )]);
    $transaction = tracedRequest();

    $response = app(GroqStream::class)->respond([
        ['role' => 'system', 'content' => 'You are the docs assistant.'],
        ['role' => 'user', 'content' => 'My token is token=abc123secret, who do I email?'],
    ], agent: 'docs-assistant', sendInstructions: true, conversation: 'chat-1');
    expect(streamed($response))->toBe('Mail sam@example.com.');

    $spans = aiSpans($transaction);
    expect($spans)->toHaveKeys(['gen_ai.invoke_agent', 'gen_ai.chat']);

    $agent = $spans['gen_ai.invoke_agent'];
    $data = $agent->getData();
    expect($agent->getDescription())->toBe('invoke_agent docs-assistant')
        ->and($agent->getEndTimestamp())->not->toBeNull()
        ->and($spans['gen_ai.chat']->getParentSpanId())->toEqual($agent->getSpanId())
        ->and($spans['gen_ai.chat']->getDescription())->toBe('chat openai/gpt-oss-120b')
        ->and($data['gen_ai.provider.name'])->toBe('groq')
        ->and($data['gen_ai.conversation.id'])->toBe('chat-1')
        ->and($data['gen_ai.usage.input_tokens'])->toBe(120)
        ->and($data['gen_ai.usage.output_tokens'])->toBe(8)
        ->and($data['gen_ai.system_instructions'])->toBe('You are the docs assistant.')
        ->and($data['gen_ai.input.messages'])->toContain('token=[filtered]')->not->toContain('abc123secret')
        ->and($data['gen_ai.output.messages'])->toContain('Mail [email].');

    Http::assertSent(fn ($request) => $request['stream_options'] === ['include_usage' => true]);
});

test('the panel assistant never sends its system prompt, and content can be turned off', function () {
    config()->set('services.groq.key', 'test-key');
    Http::fake(['api.groq.com/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"Hi.\"}}]}\n\ndata: [DONE]\n\n")]);
    $prompt = [['role' => 'system', 'content' => 'Name: Sam'], ['role' => 'user', 'content' => 'Hello']];

    $transaction = tracedRequest();
    streamed(app(GroqStream::class)->respond($prompt, agent: 'panel-assistant'));
    $data = aiSpans($transaction)['gen_ai.invoke_agent']->getData();
    expect($data)->not->toHaveKey('gen_ai.system_instructions')->toHaveKey('gen_ai.input.messages');

    config()->set('sentry.tracing.gen_ai_content', false);
    $transaction = tracedRequest();
    streamed(app(GroqStream::class)->respond($prompt, agent: 'panel-assistant'));
    expect(aiSpans($transaction)['gen_ai.invoke_agent']->getData())
        ->not->toHaveKey('gen_ai.input.messages')->not->toHaveKey('gen_ai.output.messages');
});

test('a failed call ends the AI spans as failed, and nothing is traced without a sampled request', function () {
    config()->set('services.groq.key', 'test-key');
    Http::fake(['api.groq.com/*' => Http::response(['error' => 'down'], 500)]);
    $transaction = tracedRequest();

    app(GroqStream::class)->respond([['role' => 'user', 'content' => 'Hi']], agent: 'docs-assistant');

    expect((string) aiSpans($transaction)['gen_ai.chat']->getStatus())->toBe('internal_error');

    SentrySdk::getCurrentHub()->setSpan(null);
    app(GroqStream::class)->respond([['role' => 'user', 'content' => 'Hi']]);
});

test('assistant requests are always traced, the rest at the sample rate', function () {
    config()->set('sentry.traces_sample_rate', 0.05);
    $rate = fn (string $name) => Scrub::tracesSampler(SamplingContext::getDefault(TransactionContext::make()->setName($name)));

    expect($rate('/assistant'))->toBe(1.0)->and($rate('/ask'))->toBe(1.0)->and($rate('/machines'))->toBe(0.05);
});
