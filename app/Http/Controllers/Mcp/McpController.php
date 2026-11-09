<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Models\McpToken;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A workspace's MCP server, at {workspace}.{domain}/mcp: the
 * Streamable HTTP transport, answering each POST with JSON. A token only
 * works for the workspace it was made for, and acts as the person who
 * made it, with their role as it is now.
 */
class McpController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, McpServer $server): Response
    {
        $token = $this->token($request);
        if ($token === null) {
            return $this->unauthorized($workspace, $request->bearerToken() === null ? null : 'The access token is invalid, expired or revoked.');
        }
        // Tokens are bound to one workspace: one made for another isn't for us.
        if ($token->audience !== McpToken::WORKSPACE || $token->workspace_id !== $workspace->id) {
            return $this->unauthorized($workspace, 'This token is for a different workspace.');
        }

        $user = $token->user;
        $refusal = match (true) {
            $user->suspended_at !== null => 'This account is suspended.',
            $workspace->roleOf($user) === null => "You’re no longer a member of {$workspace->name}.",
            ! $workspace->satisfiesTwoFactor($user) => "{$workspace->name} requires two-factor sign-in. Set up an authenticator app or a passkey in ServerOS first.",
            default => null,
        };
        if ($refusal !== null) {
            return response()->json(McpServer::error(null, -32001, $refusal), 403);
        }

        $key = 'mcp:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('mcp.rate_limit', 120))) {
            return response()->json(McpServer::error(null, -32002, 'Too many requests. Slow down and try again in a minute.'), 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        $version = $request->header('Mcp-Protocol-Version');
        if (is_string($version) && $version !== '' && ! in_array($version, McpServer::VERSIONS, true)) {
            return response()->json(McpServer::error(null, -32600, "Unsupported MCP protocol version {$version}."), 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(McpServer::error(null, -32700, 'Parse error: send a JSON-RPC message.'), 400);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        // The rest of the app sees the person, as if they were signed in.
        Auth::setUser($user);
        $answer = $server->handle($payload, new McpContext($workspace, $user, $token));

        return $answer === null ? response('', 202) : response()->json($answer);
    }

    /**
     * No server-sent events stream: every answer comes back on its POST.
     */
    public function stream(): Response
    {
        return response()->json(McpServer::error(null, -32600, 'This server answers POST requests only.'), 405)->header('Allow', 'POST');
    }

    /**
     * No sessions to end: every request stands alone.
     */
    public function close(): Response
    {
        return $this->stream();
    }

    private function token(Request $request): ?McpToken
    {
        $secret = $request->bearerToken();

        return is_string($secret) && $secret !== '' && strlen($secret) <= 200
            ? McpToken::findActive($secret)?->loadMissing(['user', 'client'])
            : null;
    }

    /**
     * 401, pointing the client at how to get a token (RFC 9728).
     */
    private function unauthorized(Workspace $workspace, ?string $problem): JsonResponse
    {
        $metadata = route('mcp.resource-metadata', ['workspace' => $workspace->slug]);
        $header = 'Bearer resource_metadata="'.$metadata.'"'
            .($problem === null ? '' : ', error="invalid_token", error_description="'.addslashes($problem).'"');

        return response()->json(McpServer::error(null, -32001, $problem ?? 'Sign in: this MCP server needs an access token. Connect through OAuth, or send an API key from Settings › AI and MCP as a Bearer token.'), 401)
            ->header('WWW-Authenticate', $header);
    }
}
