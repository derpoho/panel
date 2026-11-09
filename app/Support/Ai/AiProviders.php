<?php

namespace App\Support\Ai;

use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The AI a workspace's agents run on: its own Anthropic or OpenAI key when
 * it has connected one, else ServerOS's. One way to ask for a JSON answer,
 * whichever it is, so the agents don't care.
 */
class AiProviders
{
    /** provider => label, default model, what its keys start with. */
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic', 'model' => 'claude-sonnet-5-5', 'prefix' => 'sk-ant-'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-5', 'prefix' => 'sk-'],
    ];

    /** Which of the workspace's own keys the server agent prefers. */
    public const PREFERENCE = ['anthropic', 'openai'];

    /**
     * Models offered when the provider's own list can't be read: the
     * current ones, newest and most capable first.
     */
    public const FALLBACK_MODELS = [
        'anthropic' => ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-fable-5-1' => 'Claude Fable 5.1'],
        'openai' => ['gpt-5' => 'GPT-5', 'gpt-5-mini' => 'GPT-5 mini'],
    ];

    /** A model name as the providers write them. */
    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/\[\]-]{1,80}$/';

    /** The workspace's key for a provider, if it has connected one. */
    public function key(Workspace|int $workspace, string $provider): ?WorkspaceAiKey
    {
        return WorkspaceAiKey::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * What the server agent runs on for this workspace.
     *
     * @return array{provider: string, key: string, model: string, own: bool}
     */
    public function forAgent(Workspace|int $workspace): array
    {
        foreach (self::PREFERENCE as $provider) {
            $key = $this->key($workspace, $provider);
            if ($key !== null) {
                return ['provider' => $provider, 'key' => $key->key, 'model' => $key->model ?: self::PROVIDERS[$provider]['model'], 'own' => true];
            }
        }

        return ['provider' => 'groq', 'key' => (string) config('services.groq.key'), 'model' => (string) config('services.groq.model'), 'own' => false];
    }

    /**
     * What Ask AI runs on for one chat: a model the person picked from one of
     * the workspace's own keys ("anthropic:claude-opus-5-5"), else what the
     * agent uses. A pick for a provider without a key is ignored.
     *
     * @return array{provider: string, key: string, model: string, own: bool}
     */
    public function forChat(Workspace|int $workspace, ?string $choice): array
    {
        if ($choice !== null && str_contains($choice, ':')) {
            [$provider, $model] = explode(':', $choice, 2);
            $key = isset(self::PROVIDERS[$provider]) && preg_match(self::MODEL_PATTERN, $model) === 1 ? $this->key($workspace, $provider) : null;
            if ($key !== null) {
                return ['provider' => $provider, 'key' => $key->key, 'model' => $model, 'own' => true];
            }
        }

        return $this->forAgent($workspace);
    }

    /**
     * The models each of the workspace's own keys can use, for Ask AI's
     * picker: read from the provider with the key (an hour at a time), or
     * the current ones when that fails. The key's chosen model comes first.
     *
     * @return list<array{provider: string, label: string, default: string, models: list<array{id: string, label: string}>}>
     */
    public function modelOptions(Workspace $workspace): array
    {
        $options = [];
        foreach (self::PREFERENCE as $provider) {
            $key = $this->key($workspace, $provider);
            if ($key === null) {
                continue;
            }
            $default = $key->model ?: self::PROVIDERS[$provider]['model'];
            // Only a list that came back is kept; a failed ask is tried again next time.
            $cacheKey = "ai-models:{$workspace->id}:{$provider}:".substr(hash('sha256', $key->key), 0, 16);
            $models = Cache::get($cacheKey);
            if (! is_array($models) || $models === []) {
                $models = $this->listModels($provider, $key->key);
                if ($models !== []) {
                    Cache::put($cacheKey, $models, now()->addHour());
                }
            }
            $models = $models ?: array_map(fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys(self::FALLBACK_MODELS[$provider]), self::FALLBACK_MODELS[$provider]);
            if (! in_array($default, array_column($models, 'id'), true)) {
                array_unshift($models, ['id' => $default, 'label' => $default]);
            }
            $options[] = ['provider' => $provider, 'label' => self::PROVIDERS[$provider]['label'], 'default' => $default, 'models' => array_values($models)];
        }

        return $options;
    }

    /**
     * The chat models a key can use, from the provider, or [] when it can't
     * be asked.
     *
     * @return list<array{id: string, label: string}>
     */
    private function listModels(string $provider, string $key): array
    {
        try {
            $response = $provider === 'anthropic'
                ? Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(8)->get('https://api.anthropic.com/v1/models', ['limit' => 100])
                : Http::withToken($key)->timeout(8)->get('https://api.openai.com/v1/models');
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        $models = [];
        foreach ((array) $response->json('data', []) as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id === '' || preg_match(self::MODEL_PATTERN, $id) !== 1) {
                continue;
            }
            // OpenAI lists every kind of model: only the chat ones can call tools.
            if ($provider === 'openai' && (preg_match('/^(gpt-|o\d|chatgpt-)/', $id) !== 1 || preg_match('/(embedding|audio|realtime|transcribe|tts|image|search|instruct|moderation|dall-e|whisper)/', $id) === 1)) {
                continue;
            }
            $models[] = ['id' => $id, 'label' => (string) ($model['display_name'] ?? $id), 'created' => (int) ($model['created'] ?? (isset($model['created_at']) ? strtotime((string) $model['created_at']) : 0))];
        }
        // Newest first, as both providers' consoles list them.
        usort($models, fn (array $a, array $b) => $b['created'] <=> $a['created']);

        return array_slice(array_map(fn (array $m) => ['id' => $m['id'], 'label' => $m['label']], $models), 0, 30);
    }

    /**
     * Ask for a JSON object. Returns the decoded answer and the model that
     * gave it.
     *
     * @param  array{provider: string, key: string, model: string, own: bool}  $ai
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws RuntimeException with words for the person
     */
    public function json(array $ai, string $system, string $user, int $maxTokens = 1800): array
    {
        if ($ai['key'] === '') {
            throw new RuntimeException('The agent isn’t set up on this ServerOS: there’s no model key.');
        }
        $who = $ai['own'] ? 'your '.(self::PROVIDERS[$ai['provider']]['label'] ?? $ai['provider']).' key' : 'the model';

        try {
            $response = match ($ai['provider']) {
                'anthropic' => Http::withHeaders(['x-api-key' => $ai['key'], 'anthropic-version' => '2023-06-01'])
                    ->connectTimeout(10)->timeout(120)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $ai['model'],
                        'max_tokens' => $maxTokens,
                        'temperature' => 0.2,
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $user]],
                    ]),
                'openai' => Http::withToken($ai['key'])->connectTimeout(10)->timeout(120)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $ai['model'],
                        'max_completion_tokens' => $maxTokens * 2,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ]),
                default => Http::withToken($ai['key'])->connectTimeout(10)->timeout(90)
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => $ai['model'],
                        'temperature' => 0.2,
                        'max_completion_tokens' => $maxTokens,
                        'reasoning_effort' => 'medium',
                        'include_reasoning' => false,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ]),
            };
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn’t reach {$who}. Try again in a minute.");
        }

        if (! $response->successful()) {
            $why = (string) ($response->json('error.message') ?? '');
            throw new RuntimeException(match (true) {
                in_array($response->status(), [401, 403], true) => ucfirst($who).' was refused. Check it in Settings › AI and MCP.',
                $response->status() === 404 && $ai['own'] => "That model isn’t available to {$who}: ".$ai['model'].'.',
                $response->status() === 429 => ucfirst($who).' is out of requests for now. Try again in a minute.',
                default => ucfirst($who).' didn’t answer ('.$response->status().')'.($why !== '' ? ': '.rtrim(mb_substr($why, 0, 160), '.') : '').'. Try again in a minute.',
            });
        }

        $text = $ai['provider'] === 'anthropic'
            ? implode('', array_map(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'text' ? (string) ($c['text'] ?? '') : '', (array) $response->json('content', [])))
            : (string) $response->json('choices.0.message.content');

        // Anthropic has no JSON mode: take the object out of whatever it wrapped it in.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $answer = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($answer)) {
            throw new RuntimeException('The model’s answer wasn’t in the shape it was asked for. Try again.');
        }

        return [$answer, (string) ($response->json('model') ?: $ai['model'])];
    }

    /**
     * Check a key works, before it's saved.
     *
     * @throws RuntimeException with words for the person
     */
    public function verify(string $provider, string $key): void
    {
        try {
            $response = match ($provider) {
                'anthropic' => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 1]),
                default => Http::withToken($key)->timeout(15)->get('https://api.openai.com/v1/models'),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Couldn’t reach '.self::PROVIDERS[$provider]['label'].' to check the key. Try again.');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException(self::PROVIDERS[$provider]['label'].' didn’t accept that key.');
        }
        if (! $response->successful()) {
            throw new RuntimeException(self::PROVIDERS[$provider]['label'].' couldn’t check the key right now ('.$response->status().'). Try again.');
        }
    }
}
