<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Confirm your password" before sensitive settings, except for Google and
 * GitHub sign-ups who've never chosen one: they couldn't, and the page is
 * where they set it.
 */
class RequirePasswordIfSet extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        /** @var Request $request */
        if ($request->user() !== null && $request->user()->password_set_at === null) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
