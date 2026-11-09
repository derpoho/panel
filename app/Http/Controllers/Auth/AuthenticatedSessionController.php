<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\LogoutResponse as SignOutResponse;
use App\Support\Extensions\Extensions;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController as FortifyAuthenticatedSessionController;

/**
 * Fortify's sign-in and sign-out, except that signing out only signs out
 * this browser. Laravel's logout() replaces the remember token, so every
 * other device that ticked "Remember me" (or used a passkey, Google or
 * GitHub) would be signed out the next time its session ran out. Signing
 * someone out everywhere (User::signOutEverywhere) still replaces it.
 *
 * A package can send the browser somewhere first (SignInHooks::signingOut),
 * such as an SSO identity provider's logout URL, and LogoutResponse sends
 * the browser there instead of the front page.
 */
class AuthenticatedSessionController extends FortifyAuthenticatedSessionController
{
    public function destroy(Request $request): LogoutResponse
    {
        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            $url = $hooks->signingOut($request);
            if ($url !== null) {
                $request->attributes->set(SignOutResponse::REDIRECT, $url);
            }
        }

        if ($this->guard instanceof SessionGuard) {
            $this->guard->logoutCurrentDevice();
        } else {
            $this->guard->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return app(LogoutResponse::class);
    }
}
