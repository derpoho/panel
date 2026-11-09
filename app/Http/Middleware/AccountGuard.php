<?php

namespace App\Http\Middleware;

use App\Support\Admin\Impersonation;
use App\Support\Extensions\Extensions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account rules on every page: a suspended account is signed out, a
 * View as whose admin has lost their access ends, and an admin viewing as
 * someone can look but can't change how that person signs in, give out
 * keys that act as them, delete their account or pay for anything.
 */
class AccountGuard
{
    /** Routes an admin viewing as someone can't use. */
    private const array NOT_WHILE_VIEWING_AS = [
        // The MCP terms are theirs to accept.
        'mcp-terms.accept',
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'passkey.confirm',
        'passkey.store',
        'passkey.registration-options',
        'passkey.destroy',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'social.connect',
        'social.disconnect',
        // The way back from Google or GitHub: a connect the admin started
        // for their own account must never land on this person's.
        'oauth.callback',
        'sessions.destroy',
        'sessions.destroy-others',
        // Their own domain signs the browser in as them there, with no View
        // as session to go with it.
        'workspace.domains.go',
    ];

    /**
     * Keys and apps an admin viewing as someone can't make or approve: they'd
     * go on acting as the person, credited to them, long after the View as ended.
     */
    private const array KEYS = [
        'workspace.settings.mcp.keys.store',
        'workspace.settings.api.keys.store',
        'workspace.settings.scim.tokens.store',
        'mcp.oauth.approve',
    ];

    /**
     * Accounts an admin viewing as someone can't connect: their own GitHub
     * would be named to the workspace and its repos given deploy access.
     */
    private const array CONNECTIONS = [
        'workspace.github.connect',
        'github.setup',
        'workspace.github.destroy',
    ];

    /**
     * Workflows run afterwards as whoever saved them, named to the
     * workspace, long after the View as ended.
     */
    private const array WORKFLOWS = [
        'workspace.workflows.store',
        'workspace.workflows.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->suspended_at !== null && ! Impersonation::active($request)) {
            return $this->signOut($request, 'This account has been suspended. If you think that’s a mistake, email '.config('mail.support.address').'.');
        }

        // Viewing as someone whose admin no longer is one (or is gone):
        // nothing more is done in their name. The session ends as revoked.
        if (Impersonation::active($request) && Impersonation::impersonator($request) === null) {
            Impersonation::stop($request);

            return $this->signOut($request, 'Your View as session ended because your admin access was removed.');
        }

        $route = $request->route()?->getName();
        // A package's own, like billing (App\Support\Extensions).
        $offLimits = app(Extensions::class)->offLimitsMessage($route);
        if (Impersonation::active($request) && ($offLimits !== null || in_array($route, [...self::NOT_WHILE_VIEWING_AS, ...self::KEYS, ...self::CONNECTIONS, ...self::WORKFLOWS], true))) {
            $message = match (true) {
                $offLimits !== null => $offLimits,
                in_array($route, self::WORKFLOWS, true) => 'You’re viewing as this person, so saving workflows is off limits. They’d go on running as them.',
                in_array($route, self::KEYS, true) => 'You’re viewing as this person, so keys and apps that act as them are off limits.',
                in_array($route, self::CONNECTIONS, true) => 'You’re viewing as this person, so their GitHub connection is off limits.',
                $route === 'workspace.domains.go' => 'You’re viewing as this person, so their own domain is off limits: it would sign you in there as them, outside View as.',
                default => 'You’re viewing as this person, so their sign-in settings are off limits.',
            };

            if ($request->header('X-Inertia') && ! $request->isMethod('GET')) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

                return back();
            }

            abort(403, $message);
        }

        return $next($request);
    }

    /** Out, with a word on the sign-in page about why. */
    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->to(rtrim((string) config('app.url'), '/').'/login')->with('status', $message);
    }
}
