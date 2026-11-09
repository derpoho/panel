<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After signing in or registering, send users back to the page they were
 * trying to reach: usually a workspace subdomain, or an invitation link.
 * Inertia can't follow a cross-origin redirect over XHR, so tell it to do a
 * full page visit instead.
 */
class LoginResponse implements LoginResponseContract, RegisterResponseContract, TwoFactorLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return $request->routeIs('register.store')
                ? new JsonResponse('', 201)
                : new JsonResponse(['two_factor' => false]);
        }

        // A new account has just started its trial: celebrate on the first
        // workspace page they reach (after verifying their email).
        if ($request->routeIs('register.store')) {
            $request->session()->put('celebrate_trial', true);
        }

        $url = redirect()->intended(Fortify::redirects('login'))->getTargetUrl();

        return Inertia::location($url);
    }
}
