<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a workspace requires two-factor sign-in, members without an
 * authenticator app or a passkey are sent to set one up before they can
 * use it. Applies to everyone, owners included. A session a package
 * vouches for counts (Workspace::satisfiesTwoFactor), such as SSO, where
 * the company's identity provider handles the second factor.
 */
class RequireWorkspaceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace instanceof Workspace
            || $user === null
            || $request->routeIs('workspace.settings.security')
            || $workspace->satisfiesTwoFactor($user, $request)) {
            return $next($request);
        }

        $message = "{$workspace->name} requires two-factor sign-in. Set up an authenticator app or a passkey to continue.";

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return Inertia::location(route('workspace.settings.security', $workspace->slug));
    }
}
