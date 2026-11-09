<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\AccountEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Admin\Impersonation;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        return Inertia::render('settings/Security', self::props($request));
    }

    /**
     * Password, two-factor and passkey state for the signed-in user, shared
     * with the workspace Security tab.
     *
     * @return array<string, mixed>
     */
    public static function props(TwoFactorAuthenticationRequest $request): array
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            // False for Google and GitHub sign-ups who haven't chosen one.
            'hasPassword' => $request->user()->password_set_at !== null,
            'connectedAccounts' => self::connectedAccounts($request->user()),
            // Staff viewing as someone can't sign their devices out.
            'devices' => Impersonation::active($request) ? null : SessionController::devices($request),
            'securityEvents' => AccountEvent::forPerson($request->user()),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return $props;
    }

    /**
     * Google and GitHub, connected or not, for the providers that are set up.
     *
     * @return list<array{provider: string, label: string, connected: bool, email: string|null, connectedAt: string|null, canDisconnect: bool}>
     */
    private static function connectedAccounts(User $user): array
    {
        $accounts = $user->socialAccounts()->get()->keyBy('provider');
        $waysIn = $accounts->count() + ($user->password_set_at !== null ? 1 : 0);

        return array_map(fn (string $provider) => [
            'provider' => $provider,
            'label' => $provider === 'github' ? 'GitHub' : 'Google',
            'connected' => $accounts->has($provider),
            'email' => $accounts->get($provider)?->email,
            'connectedAt' => $accounts->get($provider)?->created_at?->toIso8601String(),
            // Never the last way to sign in.
            'canDisconnect' => $accounts->has($provider) && $waysIn > 1,
        ], array_values(array_unique([...SocialAccount::enabled(), ...$accounts->keys()->all()])));
    }

    #[Audited]
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $first = $user->password_set_at === null;
        $user->forceFill([
            'password' => $request->password,
            'password_set_at' => now(),
        ])->save();
        AccountEvent::record($user, $first ? 'password.set' : 'password.changed');

        Inertia::flash('toast', ['type' => 'success', 'message' => $first ? 'Password set. You can sign in with it now.' : __('Password updated.')]);

        return back();
    }
}
