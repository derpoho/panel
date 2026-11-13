<?php

namespace App\Support\Assistant;

use App\Support\Ai\AiProviders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AgentChat
{
    private const GROQ_FALLBACK = 'openai/gpt-oss-20b';

    /**
     * @param  array{provider: string, key: string, model: string, own: bool}  $ai
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     * @return array{text: string, calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, model: string, openai_output?: list<array<string, mixed>>}
     *
     * @throws RuntimeException
     */
    public function turn(array $ai, string $system, array $messages, array $tools, int $maxTokens = 1600): array
    {
        if ($ai['key'] === '') {
            throw new RuntimeException('The assistant isn’t set up on this ServerOS: there’s no model key.');
        }
        $who = $ai['own'] ? 'your '.(AiProviders::PROVIDERS[$ai['provider']]['label'] ?? $ai['provider']).' key' : 'the assistant';

        try {
            $response = match ($ai['provider']) {
                'anthropic' => $this->anthropic($ai, $system, $messages, $tools, $maxTokens),
                'openai' => $this->responses($ai, $system, $messages, $tools, $maxTokens),
                default => $this->groq($ai, $ai['model'], $system, $messages, $tools, $maxTokens),
            };
            if ($ai['provider'] === 'groq' && self::malformedCall($response)) {
                $response = $this->groq($ai, $ai['model'], $system, $messages, $tools, $maxTokens);
            }
            if ($ai['provider'] === 'groq' && ($response->status() === 429 || self::malformedCall($response)) && $ai['model'] !== self::GROQ_FALLBACK) {
                $response = $this->groq($ai, self::GROQ_FALLBACK, $system, $messages, $tools, $maxTokens);
            }
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn’t reach {$who}. Try again in a moment.");
        }

        if ($ai['provider'] === 'groq' && ($call = self::unofferedCall($response)) !== null) {
            return ['text' => '', 'calls' => [$call], 'model' => $ai['model']];
        }

        if (! $response->successful()) {
            $why = (string) ($response->json('error.message') ?? '');
            if (! $ai['own'] && $response->status() >= 400 && $response->status() !== 429) {
                report(new RuntimeException('Assistant model '.$response->status().': '.(string) ($response->json('error.code') ?? $response->json('error.type') ?? 'error')));
            }
            throw new RuntimeException(match (true) {
                in_array($response->status(), [401, 403], true) && $ai['own'] => ucfirst($who).' was refused. Check it in Settings › AI and MCP.',
                $response->status() === 404 && $ai['own'] => "That model isn’t available to {$who}: {$ai['model']}.",
                $response->status() === 429 => ucfirst($who).' is busy right now. Try again in a minute.',
                default => ucfirst($who).' couldn’t answer ('.$response->status().')'.($ai['own'] && $why !== '' ? ': '.rtrim(mb_substr($why, 0, 160), '.') : '').'. Try again in a moment.',
            });
        }

        return match ($ai['provider']) {
            'anthropic' => $this->readAnthropic($response, $ai['model']),
            'openai' => $this->readResponses($response, $ai['model']),
            default => $this->readOpenai($response, $ai['model']),
        };
    }

    private static function malformedCall(Response $response): bool
    {
        return $response->status() === 400
            && in_array($response->json('error.code'), ['tool_use_failed', 'invalid_tool_call', 'output_parse_failed'], true)
            && self::unofferedCall($response) === null;
    }

    /** @return array{id: string, name: string, arguments: array<string, mixed>}|null */
    private static function unofferedCall(Response $response): ?array
    {
        if ($response->status() !== 400 || $response->json('error.code') !== 'tool_use_failed') {
            return null;
        }
        $generated = json_decode((string) $response->json('error.failed_generation'), true);
        $name = is_array($generated) ? ($generated['name'] ?? null) : null;
        if (! is_string($name) || preg_match('/^[a-z0-9_]{1,64}$/', $name) !== 1) {
            return null;
        }
        $arguments = $generated['arguments'] ?? [];
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return ['id' => 'call_'.substr(md5((string) microtime(true)), 0, 12), 'name' => $name, 'arguments' => is_array($arguments) ? $arguments : []];
    }

    /**
     * @param  array{provider: string, key: string, model: string, own: bool}  $ai
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     */
    private function groq(array $ai, string $model, string $system, array $messages, array $tools, int $maxTokens): Response
    {
        $wire = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $wire[] = match ($m['role']) {
                'assistant' => array_filter([
                    'role' => 'assistant',
                    'content' => ($m['content'] ?? '') !== '' ? $m['content'] : null,
                    'tool_calls' => array_map(fn (array $c) => [
                        'id' => $c['id'],
                        'type' => 'function',
                        'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ], $m['calls'] ?? []) ?: null,
                ], fn ($v) => $v !== null),
                'tool' => ['role' => 'tool', 'tool_call_id' => $m['id'], 'content' => $m['content']],
                default => ['role' => 'user', 'content' => $m['content']],
            };
        }

        $body = [
            'model' => $model,
            'messages' => $wire,
            'tools' => array_map(fn (array $t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema']]], $tools),
        ];
        $body += ['temperature' => 0.2, 'max_completion_tokens' => $maxTokens, 'reasoning_effort' => 'low', 'include_reasoning' => false];

        return Http::withToken($ai['key'])->connectTimeout(10)->timeout(90)->post('https://api.groq.com/openai/v1/chat/completions', $body);
    }

    /**
     * @param  array{provider: string, key: string, model: string, own: bool}  $ai
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     */
    private function responses(array $ai, string $system, array $messages, array $tools, int $maxTokens): Response
    {
        $input = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'assistant') {
                // Keep encrypted reasoning and its function calls together, including across approvals.
                if (($m['openai_model'] ?? null) === $ai['model'] && isset($m['openai_output'])) {
                    array_push($input, ...$m['openai_output']);

                    continue;
                }
                if (($m['content'] ?? '') !== '') {
                    $input[] = ['role' => 'assistant', 'content' => $m['content']];
                }
                foreach ($m['calls'] ?? [] as $call) {
                    $input[] = ['type' => 'function_call', 'call_id' => $call['id'], 'name' => $call['name'], 'arguments' => json_encode((object) $call['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
                }
            } elseif ($m['role'] === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => $m['id'], 'output' => $m['content']];
            } else {
                $input[] = ['role' => 'user', 'content' => $m['content']];
            }
        }

        return Http::withToken($ai['key'])->connectTimeout(10)->timeout(90)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $ai['model'],
                'instructions' => $system,
                'input' => $input,
                'tools' => array_map(fn (array $t) => ['type' => 'function', 'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema'], 'strict' => false], $tools),
                'max_output_tokens' => $maxTokens * 3,
                'store' => false,
                'include' => ['reasoning.encrypted_content'],
            ]);
    }

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, model: string, openai_output: list<array<string, mixed>>} */
    private function readResponses(Response $response, string $model): array
    {
        if ($response->json('status') !== 'completed') {
            throw new RuntimeException('Your OpenAI key returned an incomplete answer. Try again.');
        }
        $text = '';
        $calls = [];
        $output = (array) $response->json('output', []);
        foreach ($output as $item) {
            if (in_array($item['type'] ?? null, ['message', 'function_call'], true) && ($item['status'] ?? null) !== 'completed') {
                throw new RuntimeException('Your OpenAI key returned an incomplete answer. Try again.');
            }
            if (($item['type'] ?? null) === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? null) === 'refusal') {
                        throw new RuntimeException('OpenAI declined this request. Try a different question.');
                    }
                    if (($part['type'] ?? null) === 'output_text') {
                        $text .= $part['text'];
                    }
                }
            } elseif (($item['type'] ?? null) === 'function_call') {
                $arguments = json_decode((string) ($item['arguments'] ?? ''), true);
                if (! is_array($arguments) || ($item['call_id'] ?? '') === '' || ($item['name'] ?? '') === '') {
                    throw new RuntimeException('OpenAI returned an invalid tool call. Try again.');
                }
                $calls[] = ['id' => $item['call_id'], 'name' => $item['name'], 'arguments' => $arguments];
            }
        }

        return ['text' => trim($text), 'calls' => $calls, 'model' => (string) ($response->json('model') ?: $model), 'openai_output' => array_values($output)];
    }

    /**
     * @param  array{provider: string, key: string, model: string, own: bool}  $ai
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     */
    private function anthropic(array $ai, string $system, array $messages, array $tools, int $maxTokens): Response
    {
        $wire = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => $m['id'], 'content' => $m['content']];
                $last = array_key_last($wire);
                if ($last !== null && $wire[$last]['role'] === 'user' && is_array($wire[$last]['content']) && ($wire[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $wire[$last]['content'][] = $block;
                } else {
                    $wire[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }
            if ($m['role'] === 'assistant') {
                $content = ($m['content'] ?? '') !== '' ? [['type' => 'text', 'text' => $m['content']]] : [];
                foreach ($m['calls'] ?? [] as $c) {
                    $content[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['arguments']];
                }
                $wire[] = ['role' => 'assistant', 'content' => $content ?: [['type' => 'text', 'text' => '…']]];

                continue;
            }
            $wire[] = ['role' => 'user', 'content' => (string) $m['content']];
        }

        return Http::withHeaders(['x-api-key' => $ai['key'], 'anthropic-version' => '2023-06-01'])
            ->connectTimeout(10)->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $ai['model'],
                'max_tokens' => $maxTokens * 2,
                'system' => $system,
                'messages' => $wire,
                'tools' => array_map(fn (array $t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema']], $tools),
            ]);
    }

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, model: string} */
    private function readOpenai(Response $response, string $model): array
    {
        $message = (array) $response->json('choices.0.message', []);
        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $i => $call) {
            $name = (string) ($call['function']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $calls[] = ['id' => (string) ($call['id'] ?? 'call_'.$i), 'name' => $name, 'arguments' => is_array($arguments) ? $arguments : []];
        }

        return ['text' => trim((string) ($message['content'] ?? '')), 'calls' => $calls, 'model' => (string) ($response->json('model') ?: $model)];
    }

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, model: string} */
    private function readAnthropic(Response $response, string $model): array
    {
        $text = '';
        $calls = [];
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'arguments' => is_array($block['input'] ?? null) ? $block['input'] : []];
            }
        }

        return ['text' => trim($text), 'calls' => $calls, 'model' => (string) ($response->json('model') ?: $model)];
    }
}
