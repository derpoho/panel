<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Extensions\Extensions;
use App\Support\Mcp\OAuth;
use App\Support\Mcp\OAuthAudience;
use App\Support\Mcp\OAuthPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization endpoint: a signed-in person sees which app wants
 * which workspace, what it may do and where its tokens go, and approves
 * or denies. There's no remembered consent: every connection is asked
 * for. Problems with the client itself are shown here, never redirected,
 * so a bad redirect URI can't be used to bounce people anywhere.
 *
 * What the page offers and what an approval grants is the audience's the
 * request is for (OAuthAudience): a workspace's MCP server, or a package's.
 */
class OAuthAuthorizeController extends Controller
{
    private const SESSION = 'mcp_oauth_requests';

    public function show(Request $request): Response
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->query('client_id'))->first();
        if ($client === null) {
            return $this->problem('This app isn’t registered with ServerOS, or its registration has expired. Remove ServerOS from the app, add it again, then connect.');
        }

        $redirect = $request->query('redirect_uri');
        if ($redirect === null && count($client->redirect_uris) === 1) {
            $redirect = $client->redirect_uris[0];
        }
        if (! is_string($redirect) || ! $client->allowsRedirect($redirect)) {
            return $this->problem('The app asked to send you back somewhere it didn’t register. For your safety, nothing was sent.');
        }

        $state = $request->query('state');
        $state = is_string($state) ? Str::limit($state, 1000, '') : null;
        $back = fn (string $error, string $description) => $this->redirectTo($redirect, ['error' => $error, 'error_description' => $description, 'state' => $state]);

        if ($request->query('response_type') !== 'code') {
            return $back('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = (string) $request->query('code_challenge', '');
        if ($request->query('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge) !== 1) {
            return $back('invalid_request', 'PKCE with S256 is required.');
        }

        $user = $request->user();
        $resource = $request->query('resource');
        $audience = $this->audienceFor($resource);
        if ($audience === null) {
            return $back('invalid_target', 'That isn’t a ServerOS MCP server URL.');
        }

        $consent = $audience->consent($request, $user, $resource);
        if ($consent instanceof Response) {
            return $consent;
        }
        $nonce = $this->remember($request, [
            'audience' => $audience->key(),
            'client' => $client->id,
            'redirect' => $redirect,
            'challenge' => $challenge,
            'state' => $state,
            ...$consent->pending,
        ]);

        return $this->frameless(Inertia::render('auth/OAuthAuthorize', [
            'request' => $nonce,
            'client' => $this->clientProps($client, $redirect),
            ...$consent->props,
        ])->toResponse($request));
    }

    #[Audited]
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'request' => ['required', 'string', 'size:40'],
            'decision' => ['required', 'in:approve,deny'],
            'workspace' => ['nullable', 'integer'],
            'write' => ['boolean'],
            'commands' => ['boolean'],
        ]);

        // Single use: pulled from the session whichever way it goes.
        $pending = $this->pending($request);
        $auth = $pending[$data['request']] ?? null;
        unset($pending[$data['request']]);
        $request->session()->put(self::SESSION, $pending);

        // Saying no, or a page that ran out, changes nothing.
        AuditContext::nothingChanged();

        if ($auth === null || $auth['expires'] < now()->getTimestamp()) {
            return $this->problem('That approval page expired. Start connecting again from the app.');
        }
        $client = McpOAuthClient::query()->whereKey($auth['client'])->firstOrFail();

        if ($data['decision'] === 'deny') {
            return $this->redirectTo($auth['redirect'], ['error' => 'access_denied', 'error_description' => 'The person said no.', 'state' => $auth['state']]);
        }

        $user = $request->user();
        $audience = app(Extensions::class)->oauthAudience($auth['audience'] ?? McpToken::WORKSPACE);
        if ($audience === null) {
            return $this->problem('That approval page expired. Start connecting again from the app.');
        }
        $grant = $audience->approve($user, $auth, $data);
        if ($grant instanceof Response) {
            return $grant;
        }

        $code = Str::random(64);
        McpOAuthCode::query()->create([
            'audience' => $audience->key(),
            'code_hash' => hash('sha256', $code),
            'mcp_oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'workspace_id' => $grant->workspace?->id,
            'redirect_uri' => $auth['redirect'],
            'code_challenge' => $auth['challenge'],
            'scopes' => $grant->scopes,
            'resource' => $grant->resource,
            'expires_at' => now()->addMinutes(OAuth::CODE_MINUTES),
        ]);
        $audience->approved($user, $client, $grant);

        return $this->redirectTo($auth['redirect'], ['code' => $code, 'state' => $auth['state']]);
    }

    /**
     * The audience that claims this resource (none named means a workspace
     * the person picks), or null when it isn't one of ours.
     */
    private function audienceFor(mixed $resource): ?OAuthAudience
    {
        foreach (app(Extensions::class)->oauthAudiences() as $audience) {
            if ($audience->claims($resource)) {
                return $audience;
            }
        }

        return null;
    }

    /**
     * Keeps a request in the session until it's approved or denied (at
     * most ten at once, each for 15 minutes). Returns its nonce.
     *
     * @param  array<string, mixed>  $request
     */
    private function remember(Request $http, array $request): string
    {
        $nonce = Str::random(40);
        $pending = collect($this->pending($http))
            ->filter(fn (array $r) => $r['expires'] > now()->getTimestamp())
            ->take(-9)
            ->put($nonce, [...$request, 'expires' => now()->addMinutes(15)->getTimestamp()]);
        $http->session()->put(self::SESSION, $pending->all());

        return $nonce;
    }

    /**
     * The requests waiting in the session, by nonce.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pending(Request $http): array
    {
        $pending = $http->session()->get(self::SESSION, []);

        return is_array($pending) ? $pending : [];
    }

    /** @return array<string, mixed> who's asking, as the page shows it */
    private function clientProps(McpOAuthClient $client, string $redirect): array
    {
        $parts = parse_url($redirect) ?: [];

        return [
            'name' => $client->name,
            'known' => OAuth::knownApp($redirect),
            'redirectHost' => ($parts['host'] ?? null) ?: (($parts['scheme'] ?? '').'://'),
            'redirectUri' => $redirect,
            'local' => in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true),
            'registeredAt' => $client->created_at?->toIso8601String(),
        ];
    }

    /**
     * Back to the app with the outcome, saying who issued it (RFC 9207).
     * A full-page visit, since the redirect leaves ServerOS (or opens an
     * app through its own scheme).
     *
     * @param  array<string, string|null>  $params
     */
    private function redirectTo(string $uri, array $params): Response
    {
        $params = array_filter([...$params, 'iss' => OAuth::issuer()], fn ($v) => $v !== null && $v !== '');
        $url = $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return request()->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }

    private function problem(string $message): Response
    {
        return OAuthPage::problem($message);
    }

    private function frameless(Response $response): Response
    {
        return OAuthPage::frameless($response);
    }
}
