<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Docs\DocsUrl;
use App\Support\Extensions\Extensions;
use App\Support\Mcp\McpUsage;
use App\Support\Mcp\OAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › AI and MCP: how to connect an AI app, the API keys and the
 * connected apps, and what agents did lately. Everyone manages their own;
 * people who manage members see and can revoke everyone's.
 */
class WorkspaceMcpController extends Controller
{
    public function show(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();
        $seesAll = Gate::allows('manageMembers', $workspace);

        $tokens = McpToken::query()
            ->where('workspace_id', $workspace->id)
            ->where('audience', McpToken::WORKSPACE)
            ->active()
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $user->id))
            // OAuth access past its refresh window is gone for good.
            ->where(fn ($q) => $q->where('kind', 'key')->orWhere('refresh_expires_at', '>', now()))
            ->with(['user:id,name,email', 'client:id,name,redirect_uris'])
            ->latest('id')
            ->get();

        $calls = McpToolCall::query()
            ->where('workspace_id', $workspace->id)
            ->where('via', 'mcp')
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $user->id))
            ->with('token:id,name,kind,mcp_oauth_client_id', 'token.client:id,name')
            ->latest('id')
            ->limit(40)
            ->get();

        return Inertia::render('workspace/settings/Mcp', [
            'workspace' => $workspace->toPageProps(),
            'mcpUrl' => OAuth::resource($workspace),
            'guideUrl' => DocsUrl::page('guides', 'ai-apps-setup'),
            'termsAccepted' => $user->mcp_terms_accepted_at !== null,
            'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
            // The workspace's own AI keys, for the server agent and coding agents.
            'ai' => WorkspaceAiController::props($workspace, Gate::allows('manageSettings', $workspace)),
            'canChange' => $workspace->allows($user, WorkspacePermission::ManageMachines),
            'seesAll' => $seesAll,
            'scopes' => Scopes::describe(),
            'mcpCalls' => McpUsage::summary($workspace),
            // Where to get a plan with more, if anywhere (App\Support\Extensions).
            'billingUrl' => app(Extensions::class)->upgradeUrl($workspace),
            'canManageBilling' => Gate::allows('manageBilling', $workspace),
            'connections' => $tokens->map(fn (McpToken $t) => [
                'id' => $t->id,
                'kind' => $t->kind,
                'name' => $t->label(),
                'known' => $t->client ? OAuth::knownApp($t->client->redirect_uris[0] ?? '') : null,
                'prefix' => $t->kind === 'key' ? $t->token_prefix : null,
                'scopes' => $t->scopes,
                'owner' => ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email, 'you' => $t->user_id === $user->id],
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'createdAt' => $t->created_at?->toIso8601String(),
            ])->values(),
            'calls' => $calls->map(fn (McpToolCall $c) => [
                'id' => $c->id,
                'tool' => $c->tool,
                'ok' => $c->ok,
                'error' => $c->error,
                'arguments' => $c->arguments,
                'via' => $c->token?->label(),
                'durationMs' => $c->duration_ms,
                'at' => $c->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Make an API key. Answers with the key itself, the only time it's
     * shown; the panel keeps its hash.
     */
    #[Audited]
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', 'in:read,write,commands'],
        ]);

        if ($user->mcp_terms_accepted_at === null) {
            return response()->json(['message' => 'Accept the MCP terms first: it’s experimental and can change real machines.'], 422);
        }
        if ($data['access'] === 'commands' && ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['errors' => ['access' => ['Running commands needs two-factor authentication on your account.']]], 422);
        }
        if (McpToken::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('audience', McpToken::WORKSPACE)->where('kind', 'key')->active()->count() >= 20) {
            return response()->json(['errors' => ['name' => ['You have 20 keys here. Revoke one you no longer use first.']]], 422);
        }

        $scopes = match ($data['access']) {
            'read' => [Scopes::READ],
            'write' => [Scopes::READ, Scopes::WRITE],
            default => Scopes::ALL,
        };
        [$token, $secret] = McpToken::issueKey($workspace, $user, trim($data['name']), $scopes);
        Activity::record($workspace, 'mcp.key_create', $token->name, meta: ['scopes' => $token->scopes]);

        return response()->json(['id' => $token->id, 'secret' => $secret, 'scopes' => $token->scopes], 201);
    }

    /**
     * Revoke a key or a connected app, at once.
     */
    #[Audited]
    public function destroy(Request $request, Workspace $workspace, McpToken $token): RedirectResponse
    {
        abort_unless($token->workspace_id === $workspace->id, 404);
        abort_unless($token->user_id === $request->user()->id || Gate::allows('manageMembers', $workspace), 403);

        $token->revoke();
        Activity::record($workspace, 'mcp.revoke', $token->label(), meta: ['kind' => $token->kind, 'owner' => $token->user_id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $token->kind === 'key' ? "Revoked {$token->name}." : "Disconnected {$token->label()}."]);

        return back();
    }
}
