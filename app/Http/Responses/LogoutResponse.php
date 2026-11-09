<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logging out from a workspace subdomain would redirect to that subdomain's
 * home, which bounces guests to the apex login. Inertia can't follow that
 * cross-origin redirect over XHR, so send a full page visit to the apex instead.
 * A package can send it elsewhere first (SignInHooks::signingOut), such as an
 * SSO identity provider's logout.
 */
class LogoutResponse implements LogoutResponseContract
{
    /** The request attribute AuthenticatedSessionController leaves that address in. */
    public const string REDIRECT = 'sign_out.redirect';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $idp = $request->attributes->get(self::REDIRECT);
        if (is_string($idp) && $idp !== '') {
            return $request->header('X-Inertia') ? Inertia::location($idp) : redirect()->away($idp);
        }

        return Inertia::location(rtrim((string) config('app.url'), '/'));
    }
}
