<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys belong to the main domain, which browsers let every subdomain use,
 * but the WebAuthn library only accepts the exact origins it's given. On a
 * workspace's own address (acme.example.com), add that address, so adding
 * a passkey or signing in with one works there too.
 */
class AllowWorkspacePasskeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = (string) config('app.domain');
        $host = strtolower($request->getHost());

        if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
            config(['passkeys.allowed_origins' => array_values(array_unique([
                ...(array) config('passkeys.allowed_origins', []),
                $request->getSchemeAndHttpHost(),
            ]))]);
        }

        return $next($request);
    }
}
