<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SocialLoginController;
use App\Models\Activity;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Workspaces\RoleChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign in with Google, for a company: who signing in with a Google
 * account on the workspace's verified domains may join, and whether
 * members must sign in that way. Pro and Business; turning
 * things off always works, so a workspace that leaves its plan can.
 */
class WorkspaceGoogleSignInController extends Controller
{
    /**
     * What the Security page shows managers about it.
     *
     * @return array<string, mixed>
     */
    public static function props(Request $request, Workspace $workspace): array
    {
        $session = $request->hasSession() ? $request->session() : null;
        $via = $session?->get(GoogleTeamAccess::VIA);
        $hd = $session?->get(GoogleTeamAccess::HD);
        $verified = $workspace->emailDomains()->whereNotNull('verified_at')->pluck('domain')->all();

        return [
            'available' => $workspace->hasGoogleTeamAccess(),
            // The domains themselves live in their own section (WorkspaceEmailDomainController).
            'verifiedDomains' => $verified,
            'join' => $workspace->domain_join,
            'joinRole' => RoleChoice::value($workspace->domain_join_role, $workspace->domain_join_custom_role_id),
            'requireGoogle' => $workspace->require_google_sign_in,
            'isOwner' => $workspace->owner_id === $request->user()->id,
            // Whether this session would pass the requirement once it's on.
            'signedInWithDomain' => $via === 'google' && is_string($hd) && in_array($hd, $verified, true) ? $hd : null,
            'hasStaffRoles' => $workspace->hasStaffRoles(),
            'hasCustomRoles' => $workspace->hasCustomRoles(),
            'customRoles' => $workspace->hasCustomRoles()
                ? $workspace->customRoles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all()
                : [],
        ];
    }

    /** Who may join with a Google account on the verified domains, and as what. */
    #[Audited]
    public function updateJoin(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['off', 'auto', 'request'])],
            'role' => ['nullable', 'string'],
        ]);

        if ($data['mode'] !== 'off' && ! $workspace->hasGoogleTeamAccess()) {
            throw ValidationException::withMessages(['mode' => 'Joining with a verified domain is on Pro and Business.']);
        }

        $choice = RoleChoice::parse($workspace, $data['role'] ?? RoleChoice::value($workspace->domain_join_role, $workspace->domain_join_custom_role_id));
        if (is_string($choice)) {
            // Off, a role the plan no longer allows can stay as it was.
            if ($data['mode'] !== 'off') {
                throw ValidationException::withMessages(['role' => $choice]);
            }
            $choice = null;
        }

        $workspace->forceFill(['domain_join' => $data['mode']]);
        if ($choice !== null) {
            $workspace->forceFill(['domain_join_role' => $choice->role->value, 'domain_join_custom_role_id' => $choice->custom?->id]);
        }

        if (! $workspace->isDirty()) {
            AuditContext::nothingChanged();

            return back();
        }

        $workspace->save();
        Activity::record($workspace, 'sign_in.domain_join', meta: array_filter([
            'mode' => $workspace->domain_join,
            'role' => $workspace->domain_join === 'off' ? null : $choice?->label(),
        ]));
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($workspace->domain_join) {
            'auto' => 'People on your domains can join now.',
            'request' => 'People on your domains can ask to join now.',
            default => 'Only people you invite can join now.',
        }]);

        return back();
    }

    /**
     * Require Google sign-in on the workspace's domains. Whoever turns it
     * on is the owner (who's always let in) or is signed in that way now,
     * so nobody locks themselves out.
     */
    #[Audited]
    public function updateRequire(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $require = $request->validate(['require' => ['required', 'boolean']])['require'];

        if ($require) {
            if (! $workspace->hasGoogleTeamAccess()) {
                throw ValidationException::withMessages(['require' => 'Requiring Google sign-in is on Pro and Business.']);
            }
            $verified = $workspace->emailDomains()->whereNotNull('verified_at')->pluck('domain')->all();
            if ($verified === []) {
                throw ValidationException::withMessages(['require' => 'Verify a domain first.']);
            }
            if ($workspace->require_sso) {
                throw ValidationException::withMessages(['require' => 'Turn off Require SSO first. Members can only be held to one.']);
            }
            $hd = $request->session()->get(GoogleTeamAccess::HD);
            $signedIn = $request->session()->get(GoogleTeamAccess::VIA) === 'google' && in_array($hd, $verified, true);
            if ($workspace->owner_id !== $request->user()->id && ! $signedIn) {
                throw ValidationException::withMessages(['require' => 'Sign in with Google on '.implode(' or ', $verified).' first, so you can still get in.']);
            }
        }

        if ($workspace->require_google_sign_in === (bool) $require) {
            AuditContext::nothingChanged();

            return back();
        }

        $workspace->forceFill(['require_google_sign_in' => (bool) $require])->save();
        Activity::record($workspace, $require ? 'sign_in.require_google_on' : 'sign_in.require_google_off');
        Inertia::flash('toast', ['type' => 'success', 'message' => $require
            ? 'Google sign-in is now required.'
            : 'Google sign-in is no longer required.']);

        return back();
    }

    /**
     * From the page a member sees when the workspace requires Google:
     * sign out, then in again with Google, and come back here.
     */
    #[Audited]
    public function signInAgain(Request $request, Workspace $workspace): Response
    {
        $verified = $workspace->emailDomains()->whereNotNull('verified_at')->pluck('domain')->all();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AuditContext::nothingChanged();

        $request->session()->put('url.intended', $workspace->url());
        $request->session()->put(SocialLoginController::GOOGLE_DOMAIN_HINT, count($verified) === 1 ? $verified[0] : '*');

        return Inertia::location(rtrim((string) config('app.url'), '/').route('oauth.redirect', 'google', absolute: false));
    }
}
