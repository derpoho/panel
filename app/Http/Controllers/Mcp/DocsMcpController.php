<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Docs\DocsMcpServer;
use App\Mcp\McpServer;
use App\Support\Audit\NotAudited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The docs' MCP server, at docs.{domain}/mcp. No sign-in: it
 * only reads the published docs, so it's limited by address instead.
 */
#[NotAudited('Reads the public docs only')]
class DocsMcpController extends Controller
{
    public function __invoke(Request $request, DocsMcpServer $server): Response
    {
        $key = 'mcp-docs:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 120)) {
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

        $answer = $server->handle($payload);

        return $answer === null ? response('', 202) : response()->json($answer);
    }

    /** Every answer comes back on its POST: no event stream, no sessions. */
    public function stream(): Response
    {
        return response()->json(McpServer::error(null, -32600, 'This server answers POST requests only.'), 405)->header('Allow', 'POST');
    }
}
