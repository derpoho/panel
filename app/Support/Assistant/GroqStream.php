<?php

namespace App\Support\Assistant;

use App\Support\Sentry\AiTrace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asks Groq and streams the answer back as plain text while it's written,
 * for the panel's assistant and the docs site's. Problems come back as
 * JSON with an error to show.
 */
class GroqStream
{
    /** Used when the main model is rate limited. */
    private const FALLBACK_MODEL = 'openai/gpt-oss-20b';

    private const float TEMPERATURE = 0.2;

    private const int MAX_TOKENS = 900;

    /**
     * @param  list<array{role: string, content: string}>  $prompt
     * @param  (callable(string): void)|null  $done  given the whole answer once it's written
     * @param  string  $agent  which assistant this is, for Sentry's Agent Tracing
     * @param  bool  $sendInstructions  whether Sentry may see the system prompt (not when it's about a person)
     * @param  string|null  $conversation  the chat's own ID, when it has one
     */
    public function respond(array $prompt, ?callable $done = null, string $agent = 'assistant', bool $sendInstructions = false, ?string $conversation = null): StreamedResponse|JsonResponse
    {
        $key = config('services.groq.key');
        if (blank($key)) {
            return response()->json(['error' => 'The assistant isn’t set up here yet.'], 503);
        }

        $trace = (new AiTrace($agent, (string) config('services.groq.model'), $prompt, [
            'temperature' => self::TEMPERATURE,
            'max_tokens' => self::MAX_TOKENS,
        ], $sendInstructions, $conversation))->start();

        try {
            $response = $this->ask($key, config('services.groq.model'), $prompt);

            // Rate limited: the smaller model has its own allowance, so try that.
            if ($response->status() === 429 && config('services.groq.model') !== self::FALLBACK_MODEL) {
                $response = $this->ask($key, self::FALLBACK_MODEL, $prompt);
            }
        } catch (ConnectionException $e) {
            report($e);
            $trace->fail('connection');

            return response()->json(['error' => 'Couldn’t reach the assistant. Try again in a moment.'], 502);
        }

        if ($response->status() === 429) {
            $trace->fail('rate_limited');
            preg_match('/try again in (?:(\d+)m)?([\d.]+)s/i', $response->body(), $wait);
            $seconds = isset($wait[2]) ? (int) ceil(((int) ($wait[1] ?: 0)) * 60 + (float) $wait[2]) : null;

            return response()->json(['error' => $seconds !== null && $seconds < 120
                ? "The assistant is busy. Try again in {$seconds} seconds."
                : 'The assistant is busy right now. Try again in a minute.'], 429);
        }

        if ($response->failed()) {
            $trace->fail('http_'.$response->status());
            report(new \RuntimeException('Groq '.$response->status().': '.substr($response->body(), 0, 500)));

            return response()->json(['error' => 'The assistant couldn’t answer that. Try again in a moment.'], 502);
        }

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body, $done, $trace) {
            $buffer = '';
            $answer = '';
            $model = $usage = $finish = null;
            while (! $body->eof()) {
                $buffer .= $body->read(8192);
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + 1);
                    if (! str_starts_with($line, 'data:') || ($json = trim(substr($line, 5))) === '[DONE]') {
                        continue;
                    }
                    $chunk = json_decode($json, true);
                    $model ??= $chunk['model'] ?? null;
                    $finish = $chunk['choices'][0]['finish_reason'] ?? $finish;
                    $usage = $chunk['usage'] ?? $chunk['x_groq']['usage'] ?? $usage;
                    $text = $chunk['choices'][0]['delta']['content'] ?? '';
                    if ($text !== '') {
                        $answer .= $text;
                        echo $text;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }
            }
            $trace->finish($answer, $model, $usage, $finish);
            if ($done !== null) {
                $done($answer);
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    private function ask(string $key, string $model, array $messages): Response
    {
        return Http::withToken($key)
            ->withOptions(['stream' => true])
            ->connectTimeout(10)
            ->timeout(90)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'stream' => true,
                // The last chunk carries the token counts (for Sentry's Agent Tracing).
                'stream_options' => ['include_usage' => true],
                'temperature' => self::TEMPERATURE,
                // Groq counts this against the per-minute allowance up front.
                'max_completion_tokens' => self::MAX_TOKENS,
                'reasoning_effort' => 'low',
                'include_reasoning' => false,
                'messages' => $messages,
            ]);
    }
}
