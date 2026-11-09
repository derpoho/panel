<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Support\Extensions\Extensions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The token endpoint: an authorization code (with its PKCE verifier) for
 * an access token and a refresh token, and a refresh token for new ones.
 * Refresh tokens rotate: each works once.
 */
class OAuthTokenController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchange($request),
            'refresh_token' => $this->refresh($request),
            default => $this->fail('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /**
     * RFC 7009: forget a token. Always answers 200, so it can't be used to
     * find out which tokens exist.
     */
    public function revoke(Request $request): JsonResponse
    {
        $secret = (string) $request->input('token', '');
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id', ''))->first();
        if ($secret !== '' && $client !== null) {
            $hash = McpToken::hash($secret);
            McpToken::query()->active()
                ->where('mcp_oauth_client_id', $client->id)
                ->where(fn ($q) => $q->where('token_hash', $hash)->orWhere('refresh_hash', $hash))
                ->get()
                ->each(function (McpToken $token): void {
                    $token->revoke();
                    // The app ended it, not the person: "Claude disconnected
                    // itself from MCP (Sam's connection)". A package's
                    // audience's tokens have no workspace to tell.
                    if ($token->workspace_id !== null) {
                        Activity::system($token->workspace_id, 'mcp.revoke', $token->label(), meta: array_filter([
                            'kind' => 'oauth', 'by_client' => true, 'person' => $token->user->name,
                        ]));
                    }
                });
        }

        return response()->json(new \stdClass)->header('Cache-Control', 'no-store');
    }

    private function exchange(Request $request): JsonResponse
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id'))->first();
        if ($client === null) {
            return $this->fail('invalid_client', 'Unknown client_id.', 401);
        }
        $verifier = (string) $request->input('code_verifier', '');
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return $this->fail('invalid_request', 'Send the PKCE code_verifier.');
        }

        $grant = DB::transaction(function () use ($request, $client, $verifier) {
            $code = McpOAuthCode::query()->lockForUpdate()
                ->where('code_hash', hash('sha256', (string) $request->input('code', '')))
                ->first();

            $valid = $code !== null
                && $code->used_at === null
                && $code->expires_at->isFuture()
                && $code->mcp_oauth_client_id === $client->id
                && hash_equals($code->redirect_uri, (string) $request->input('redirect_uri', $code->redirect_uri))
                && hash_equals($code->code_challenge, self::challenge($verifier))
                && (! $request->filled('resource') || rtrim((string) $request->input('resource'), '/') === rtrim($code->resource, '/'));
            if (! $valid) {
                return null;
            }
            $code->forceFill(['used_at' => now()])->save();

            return $code;
        });

        // A code for an audience nothing installed knows any more is no good either.
        $scopes = $grant === null ? null : self::scopes($grant->audience, $grant->scopes);
        if ($grant === null || $scopes === null) {
            return $this->fail('invalid_grant', 'The code is invalid, expired, already used, or doesn’t match this client, redirect or verifier.');
        }

        $access = McpToken::secret('access');
        $refresh = McpToken::secret('refresh');
        $token = McpToken::query()->create([
            'audience' => $grant->audience,
            'workspace_id' => $grant->workspace_id,
            'user_id' => $grant->user_id,
            'mcp_oauth_client_id' => $client->id,
            'kind' => 'oauth',
            'name' => $client->name,
            'scopes' => $scopes,
            'token_hash' => McpToken::hash($access),
            'token_prefix' => substr($access, 0, 12),
            'refresh_hash' => McpToken::hash($refresh),
            'expires_at' => now()->addMinutes(McpToken::ACCESS_MINUTES),
            'refresh_expires_at' => now()->addDays(McpToken::REFRESH_DAYS),
        ]);

        return $this->tokens($token, $access, $refresh);
    }

    private function refresh(Request $request): JsonResponse
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id'))->first();
        if ($client === null) {
            return $this->fail('invalid_client', 'Unknown client_id.', 401);
        }

        $access = McpToken::secret('access');
        $refresh = McpToken::secret('refresh');
        $token = DB::transaction(function () use ($request, $client, $access, $refresh) {
            $token = McpToken::query()->active()->lockForUpdate()
                ->where('refresh_hash', McpToken::hash((string) $request->input('refresh_token', '')))
                ->where('mcp_oauth_client_id', $client->id)
                ->first();
            if ($token === null || $token->refresh_expires_at === null || $token->refresh_expires_at->isPast()) {
                return null;
            }
            $asked = self::scopes($token->audience, (string) $request->input('scope'));
            if ($asked === null) {
                return null;
            }
            // A narrower scope may be asked for; never a wider one.
            if ($request->filled('scope')) {
                $token->scopes = array_values(array_intersect($token->scopes, $asked));
            }
            $token->forceFill([
                'token_hash' => McpToken::hash($access),
                'token_prefix' => substr($access, 0, 12),
                'refresh_hash' => McpToken::hash($refresh),
                'expires_at' => now()->addMinutes(McpToken::ACCESS_MINUTES),
                'refresh_expires_at' => now()->addDays(McpToken::REFRESH_DAYS),
            ])->save();

            return $token;
        });

        return $token === null
            ? $this->fail('invalid_grant', 'The refresh token is invalid, expired or revoked. Connect again.')
            : $this->tokens($token, $access, $refresh);
    }

    private function tokens(McpToken $token, string $access, string $refresh): JsonResponse
    {
        return response()->json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => McpToken::ACCESS_MINUTES * 60,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $token->scopes),
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * The known scopes for what the token is for (its audience: a
     * workspace, or a package's), or null when nothing installed knows it.
     *
     * @param  string|list<string>|null  $scopes
     * @return list<string>|null
     */
    private static function scopes(?string $audience, string|array|null $scopes): ?array
    {
        return app(Extensions::class)->oauthAudience($audience ?? McpToken::WORKSPACE)?->normalise($scopes);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function fail(string $error, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status)->header('Cache-Control', 'no-store');
    }
}
