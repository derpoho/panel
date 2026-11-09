<?php

namespace App\Support\Mcp;

use App\Mcp\Scopes;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;

/**
 * The OAuth 2.1 authorization server behind MCP: where it lives, which
 * workspace an MCP URL means, which redirect URIs an app may register,
 * and which well-known AI apps a redirect belongs to. What a token can be
 * for besides a workspace's MCP server is a package's (OAuthAudience).
 */
final class OAuth
{
    /** Minutes an authorization code lives. */
    public const CODE_MINUTES = 10;

    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** The MCP server URL a token for this workspace is for (its audience). */
    public static function resource(Workspace $workspace): string
    {
        return route('mcp', ['workspace' => $workspace->slug]);
    }

    /**
     * The workspace an MCP URL is for, if it's one of ours.
     */
    public static function workspaceFor(?string $resource): ?Workspace
    {
        if (! is_string($resource) || $resource === '') {
            return null;
        }
        $host = strtolower((string) parse_url($resource, PHP_URL_HOST));
        $suffix = '.'.strtolower((string) config('app.domain'));
        if (! str_ends_with($host, $suffix)) {
            return null;
        }
        $workspace = Workspace::query()->where('slug', substr($host, 0, -strlen($suffix)))->first();

        // Exactly the MCP URL (a trailing slash is the same URL).
        return $workspace !== null && rtrim($resource, '/') === rtrim(self::resource($workspace), '/') ? $workspace : null;
    }

    /**
     * Whether an app may register this redirect URI: HTTPS anywhere,
     * plain HTTP only back to this computer (RFC 8252), or an app's own
     * scheme (cursor://, vscode://). Never javascript:, data:, file: and
     * the like, and never with a fragment.
     */
    public static function redirectAllowed(mixed $uri): bool
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > 2000 || preg_match('/[\s<>"\\\\]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        return match (true) {
            $scheme === 'https' => $host !== '' && ! isset($parts['user']) && ! isset($parts['pass']),
            $scheme === 'http' => in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true),
            in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms', 'intent', 'chrome', 'view-source'], true) => false,
            // An app's own scheme: letters, digits and dots, like cursor or com.example.app.
            default => preg_match('/^[a-z][a-z0-9.+-]{1,60}$/', $scheme) === 1,
        };
    }

    /**
     * The well-known AI app a redirect URI belongs to, judged by where
     * tokens go (which an app can't fake and still receive them), not by
     * the name it gave itself.
     */
    public static function knownApp(string $redirectUri): ?string
    {
        $parts = parse_url($redirectUri) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        return match (true) {
            $scheme === 'https' && in_array($host, ['claude.ai', 'claude.com'], true) => 'claude',
            $scheme === 'https' && in_array($host, ['chatgpt.com', 'chat.openai.com', 'platform.openai.com'], true) => 'openai',
            $scheme === 'cursor' => 'cursor',
            $scheme === 'https' && $host === 'vscode.dev', $scheme === 'vscode' => 'copilot',
            $scheme === 'windsurf' => 'windsurf',
            default => null,
        };
    }

    /**
     * Server metadata (RFC 8414).
     *
     * @return array<string, mixed>
     */
    public static function serverMetadata(): array
    {
        $issuer = self::issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            // Every audience's, the workspace's first.
            'scopes_supported' => array_merge(...array_values(array_map(fn (OAuthAudience $audience) => $audience->scopes(), app(Extensions::class)->oauthAudiences()))),
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => 'https://docs.'.config('app.domain').'/',
        ];
    }

    /**
     * Protected resource metadata for one workspace's MCP server (RFC 9728).
     *
     * @return array<string, mixed>
     */
    public static function resourceMetadata(Workspace $workspace): array
    {
        return [
            'resource' => self::resource($workspace),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => Scopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => "ServerOS · {$workspace->name}",
            'resource_documentation' => 'https://docs.'.config('app.domain').'/',
        ];
    }
}
