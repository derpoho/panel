<?php

namespace App\Http\Middleware;

use App\Api\ApiRunner;
use App\Enums\Limit;
use App\Mcp\McpContext;
use App\Models\McpToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The REST API's door: a workspace API key as a bearer token, acting as
 * the person who made it with their role as it is now, limited to a
 * number of requests a minute by the workspace's plan. Every answer says
 * how many are left (RateLimit-* headers).
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        $token = $secret === null ? null : McpToken::query()
            ->where('token_hash', McpToken::hash($secret))
            ->where('audience', McpToken::API)
            ->active()
            ->with(['workspace', 'user'])
            ->first();

        if ($token === null || $token->workspace_id === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return self::fail(401, 'unauthenticated', $secret === null
                ? 'Send an API key as a bearer token: Authorization: Bearer sos_… Make one in Settings › API.'
                : 'That API key is invalid or has been revoked.')
                ->header('WWW-Authenticate', 'Bearer');
        }

        $workspace = $token->workspace;
        $user = $token->user;
        $refusal = match (true) {
            $user->suspended_at !== null => 'This account is suspended.',
            $workspace->roleOf($user) === null => "The key’s owner is no longer a member of {$workspace->name}.",
            ! $workspace->satisfiesTwoFactor($user) => "{$workspace->name} requires two-factor sign-in, and the key’s owner hasn’t set it up.",
            default => null,
        };
        if ($refusal !== null) {
            return self::fail(403, 'forbidden', $refusal);
        }

        if (! $workspace->isActive()) {
            return self::fail(402, 'plan_required', "{$workspace->name} has no plan, so its API is off. An owner can choose one in Billing.");
        }

        $limit = $workspace->limitOf(Limit::ApiRequestsPerMinute) ?? PHP_INT_MAX;
        $key = 'api:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = RateLimiter::availableIn($key);

            $response = self::limits(self::fail(429, 'rate_limited', "Too many requests: this key may make {$limit} a minute. Try again in {$retry} seconds."), $limit, 0, $retry);
            $response->headers->set('Retry-After', (string) $retry);

            return $response;
        }
        RateLimiter::hit($key, 60);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        // The rest of the app sees the person, as if they were signed in.
        Auth::setUser($user);
        $request->attributes->set('api.context', new McpContext($workspace, $user, $token, 'api'));

        return self::limits($next($request), $limit, RateLimiter::remaining($key, $limit), RateLimiter::availableIn($key));
    }

    private static function fail(int $status, string $type, string $message): JsonResponse
    {
        return response()->json(ApiRunner::error($status, $type, $message)[1], $status);
    }

    private static function limits(Response $response, int $limit, int $remaining, int $reset): Response
    {
        $response->headers->set('RateLimit-Limit', (string) $limit);
        $response->headers->set('RateLimit-Remaining', (string) max(0, $remaining));
        $response->headers->set('RateLimit-Reset', (string) max(0, $reset));

        return $response;
    }
}
