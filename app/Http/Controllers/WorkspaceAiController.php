<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A workspace's own AI keys, in Settings › AI and MCP: the server agent and
 * coding agents run on them instead of ServerOS's. Each key is checked with
 * its provider before it's kept, and never shown again.
 */
class WorkspaceAiController extends Controller
{
    /**
     * What the settings page shows: each provider, connected or not.
     *
     * @return array{providers: list<array<string, mixed>>, agentUses: string, canManage: bool}
     */
    public static function props(Workspace $workspace, bool $canManage): array
    {
        $keys = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->with('user:id,name')->get()->keyBy('provider');
        $uses = app(AiProviders::class)->forAgent($workspace);

        return [
            'providers' => array_values(collect(AiProviders::PROVIDERS)->map(fn (array $p, string $provider) => [
                'provider' => $provider,
                'label' => $p['label'],
                'defaultModel' => $p['model'],
                'connected' => $keys->has($provider),
                'hint' => $keys->get($provider)?->hint,
                'model' => $keys->get($provider)?->model,
                'by' => $keys->get($provider)?->user?->name,
                'connectedAt' => $keys->get($provider)?->created_at?->toIso8601String(),
            ])->all()),
            'agentUses' => $uses['own'] ? AiProviders::PROVIDERS[$uses['provider']]['label'].' · '.$uses['model'] : 'ServerOS',
            'canManage' => $canManage,
        ];
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, string $provider, AiProviders $ai): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless(isset(AiProviders::PROVIDERS[$provider]), 404);
        $existing = $ai->key($workspace, $provider);
        $data = $request->validate([
            'key' => [$existing ? 'nullable' : 'required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            'model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/\[\]-]+$/'],
        ], ['key.regex' => 'Paste the key on its own, with no spaces.', 'model.regex' => 'That isn’t a model name.']);

        $key = trim((string) ($data['key'] ?? ''));
        if ($key !== '') {
            try {
                $ai->verify($provider, $key);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['key' => $e->getMessage()]);
            }
        }

        WorkspaceAiKey::query()->updateOrCreate(['workspace_id' => $workspace->id, 'provider' => $provider], array_filter([
            'key' => $key !== '' ? $key : null,
            'hint' => $key !== '' ? '…'.substr($key, -4) : null,
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'user_id' => $key !== '' ? $request->user()->id : null,
            'verified_at' => $key !== '' ? now() : null,
        ], fn ($v) => $v !== null) + ['model' => trim((string) ($data['model'] ?? '')) ?: null]);
        Activity::record($workspace, $existing ? 'ai.update' : 'ai.connect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, string $provider): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $request->merge(['provider' => $provider])->validate(['provider' => [Rule::in(array_keys(AiProviders::PROVIDERS))]]);
        $deleted = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->where('provider', $provider)->delete();
        if ($deleted > 0) {
            Activity::record($workspace, 'ai.disconnect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());
        }

        return back();
    }
}
