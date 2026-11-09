<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * What a package does when people sign in and out of the panel, like
 * single sign-on. Registered with Extensions::addSignInHooks;
 * override only what's needed. Every one is asked, in the order added.
 */
abstract class SignInHooks
{
    /**
     * The package's sign-in routes, and how each signs people in (password,
     * passkey, sso and so on), for the account's sign-in history.
     *
     * @return array<string, string>
     */
    public function signInRoutes(): array
    {
        return [];
    }

    /**
     * Turn down a sign-in with Google or GitHub: the reason to show, or null
     * to let it through. $user is the account it would sign in to, if any.
     */
    public function refuseSocialSignIn(Request $request, ?User $user, string $email): ?string
    {
        return null;
    }

    /** A sign-in just completed this way (the first step's method, before any code prompt). */
    public function signedIn(Request $request, User $user, string $method): void
    {
        //
    }

    /** A password sign-in stopped at the code prompt: forget what another way in left in the session. */
    public function challenged(Request $request): void
    {
        //
    }

    /**
     * This browser is signing out: where to send it afterwards (an identity
     * provider's logout, say), or null for the front page.
     */
    public function signingOut(Request $request): ?string
    {
        return null;
    }

    /** Whether this session meets the workspace's two-factor requirement the package's way. */
    public function meetsTwoFactor(Workspace $workspace, User $user, Request $request): bool
    {
        return false;
    }

    /**
     * What to carry when the dashboard hands this session to the workspace's
     * own domain, next to how it signed in.
     *
     * @return array<string, mixed>
     */
    public function handoff(Request $request): array
    {
        return [];
    }

    /**
     * On the workspace's own domain, signed in from a hand-off: put back what
     * handoff() carried.
     *
     * @param  array<string, mixed>  $carried
     */
    public function receiveHandoff(Request $request, array $carried, User $user): void
    {
        //
    }
}
