<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Domains\CustomDomains;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once a workspace has its own domain, its pages on our subdomain send
 * people there, signed in (through the hand-off), to the same page.
 * Changes (POST and friends) still work where they are.
 */
class SendToCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        if (! $request->isMethod('GET') || $request->attributes->has('custom_domain') || ! $workspace instanceof Workspace
            || $request->routeIs('workspace.domains.go') || $request->expectsJson() && ! $request->header('X-Inertia')
            || CustomDomains::dashboardHostFor($workspace->slug) === null) {
            return $next($request);
        }

        $go = route('workspace.domains.go', ['workspace' => $workspace->slug, 'to' => $request->getRequestUri()]);

        return $request->header('X-Inertia') ? Inertia::location($go) : redirect($go);
    }
}
