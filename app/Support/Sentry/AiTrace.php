<?php

namespace App\Support\Sentry;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

/**
 * Sentry Agent Tracing for our assistants, which call Groq over plain HTTP
 * rather than through laravel/ai (whose calls Sentry would trace on its
 * own): one invoke_agent span per answer with a chat span inside, named
 * and shaped the way Sentry's own integration does, so they show under
 * Explore › Agents with their tokens, timing and conversation.
 *
 * Questions and answers go through Scrub first. The panel's system prompt
 * names the person, their workspace and machines, so it's never sent;
 * the docs and sales prompts are only our docs, so they are. Turn content
 * off altogether with SENTRY_AI_CONTENT=false.
 *
 * Nothing happens unless the request is traced (always, for the
 * assistants' routes: see Scrub::tracesSampler).
 */
final class AiTrace
{
    private const string PROVIDER = 'groq';

    private ?Span $agent = null;

    private ?Span $chat = null;

    /**
     * @param  list<array{role: string, content: string}>  $messages  the whole prompt, system message first
     * @param  array{temperature?: float, max_tokens?: int}  $request
     */
    public function __construct(
        private readonly string $agentName,
        private readonly string $model,
        private readonly array $messages,
        private readonly array $request = [],
        private readonly bool $sendInstructions = false,
        private readonly ?string $conversation = null,
    ) {}

    public function start(): self
    {
        $parent = SentrySdk::getCurrentHub()->getSpan();
        if ($parent === null || ! $parent->getSampled()) {
            return $this;
        }

        $data = $this->base('invoke_agent');
        if (self::content()) {
            $data['gen_ai.input.messages'] = self::json($this->input());
            $system = collect($this->messages)->firstWhere('role', 'system')['content'] ?? null;
            if ($this->sendInstructions && is_string($system)) {
                $data['gen_ai.system_instructions'] = mb_substr(Scrub::text($system), 0, 20000);
            }
        }

        $this->agent = $parent->startChild(SpanContext::make()
            ->setOp('gen_ai.invoke_agent')
            ->setDescription("invoke_agent {$this->agentName}")
            ->setOrigin('manual.ai.groq')
            ->setData($data));

        $this->chat = $this->agent->startChild(SpanContext::make()
            ->setOp('gen_ai.chat')
            ->setDescription("chat {$this->model}")
            ->setOrigin('manual.ai.groq')
            ->setData($this->base('chat')));

        return $this;
    }

    /**
     * The model answered: what it said, its tokens and why it stopped.
     *
     * @param  array<string, mixed>|null  $usage  the OpenAI-style usage block
     */
    public function finish(string $answer, ?string $responseModel = null, ?array $usage = null, ?string $finishReason = null): void
    {
        $data = array_filter([
            'gen_ai.response.model' => $responseModel,
            'gen_ai.response.finish_reasons' => $finishReason === null ? null : self::json([$finishReason]),
            'gen_ai.usage.input_tokens' => $usage['prompt_tokens'] ?? null,
            'gen_ai.usage.output_tokens' => $usage['completion_tokens'] ?? null,
            'gen_ai.usage.total_tokens' => $usage['total_tokens'] ?? null,
            'gen_ai.usage.input_tokens.cached' => $usage['prompt_tokens_details']['cached_tokens'] ?? null,
            'gen_ai.usage.output_tokens.reasoning' => $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if (self::content()) {
            $data['gen_ai.output.messages'] = self::json([[
                'role' => 'assistant',
                'parts' => [['type' => 'text', 'content' => Scrub::text($answer)]],
                'finish_reason' => $finishReason ?? 'stop',
            ]]);
        }

        foreach ([$this->chat, $this->agent] as $span) {
            $span?->setData($data)->setStatus(SpanStatus::ok())->finish();
        }
        $this->chat = $this->agent = null;
    }

    /** The call failed: Groq said no, was busy, or couldn't be reached. */
    public function fail(string $reason): void
    {
        foreach ([$this->chat, $this->agent] as $span) {
            $span?->setData(['error.type' => $reason])->setStatus(SpanStatus::internalError())->finish();
        }
        $this->chat = $this->agent = null;
    }

    /**
     * One ID for every turn of the same chat: the chat's own, or else who's
     * chatting (their session) and how the chat began (its first question),
     * hashed.
     */
    public function conversationId(): ?string
    {
        if ($this->conversation !== null) {
            return $this->conversation;
        }

        $first = collect($this->messages)->firstWhere('role', 'user')['content'] ?? null;
        $session = request()->hasSession() ? request()->session()->getId() : null;

        return is_string($first) && $session !== null
            ? substr(hash('sha256', $this->agentName.'|'.$session.'|'.$first), 0, 32)
            : null;
    }

    /** @return array<string, mixed> */
    private function base(string $operation): array
    {
        return array_filter([
            'gen_ai.operation.name' => $operation,
            'gen_ai.agent.name' => $this->agentName,
            'gen_ai.provider.name' => self::PROVIDER,
            'gen_ai.request.model' => $this->model,
            'gen_ai.request.temperature' => $this->request['temperature'] ?? null,
            'gen_ai.request.max_tokens' => $this->request['max_tokens'] ?? null,
            'gen_ai.response.streaming' => true,
            'gen_ai.conversation.id' => $this->conversationId(),
        ], fn ($value) => $value !== null);
    }

    /** @return array<int, array{role: string, parts: list<array{type: string, content: string}>}> */
    private function input(): array
    {
        return collect($this->messages)
            ->reject(fn (array $message) => $message['role'] === 'system')
            ->map(fn (array $message) => [
                'role' => $message['role'],
                'parts' => [['type' => 'text', 'content' => Scrub::text($message['content'])]],
            ])->values()->all();
    }

    private static function content(): bool
    {
        return (bool) config('sentry.tracing.gen_ai_content', true);
    }

    private static function json(mixed $value): string
    {
        return mb_substr((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 20000);
    }
}
