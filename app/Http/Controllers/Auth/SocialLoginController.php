<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Listeners\RecordAccountEvents;
use App\Models\AccountEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Extensions\Extensions;
use App\Support\ProviderAvatar;
use App\Support\TeamPush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google" and "Continue with GitHub": signs in the person
 * the account belongs to, links it to an existing account with the same
 * verified email, or creates an account and workspace. Someone with
 * two-factor on still gets the code prompt. Signed in, it connects the
 * account from Settings instead, and Settings can disconnect one as long
 * as another way to sign in is left.
 */
class SocialLoginController extends Controller
{
    /** A Google Workspace domain to ask Google for, set by RequireWorkspaceGoogleSignIn. */
    public const string GOOGLE_DOMAIN_HINT = 'oauth.google_hd_hint';

    public function __construct(private CreateWorkspace $createWorkspace) {}

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        if (! in_array($provider, SocialAccount::enabled(), true)) {
            return $this->failed('That sign-in option isn’t available yet.');
        }

        $driver = Socialite::driver($provider);

        // Sent here by a workspace that requires its company's Google
        // accounts: Google shows only accounts on that domain.
        $hint = $request->session()->pull(self::GOOGLE_DOMAIN_HINT);
        if ($provider === 'google' && is_string($hint) && $hint !== '' && $driver instanceof AbstractProvider) {
            $driver->with(['hd' => $hint]);
        }

        return $driver->redirect();
    }

    /** From Settings: off to the provider, to come back connected. */
    public function connect(Request $request, string $provider): SymfonyRedirect
    {
        if (! in_array($provider, SocialAccount::enabled(), true)) {
            return $this->backToSettings($request, 'error', 'That sign-in option isn’t available yet.');
        }

        $request->session()->put('oauth.connect', [
            'provider' => $provider,
            'return' => $this->settingsUrl($request),
        ]);

        return Socialite::driver($provider)->redirect();
    }

    #[Audited]
    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $account = $user->socialAccounts()->where('provider', $provider)->firstOrFail();
        $waysIn = $user->socialAccounts()->count() + ($user->password_set_at !== null ? 1 : 0);

        if ($waysIn <= 1) {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Set a password or connect another account first, so you can still sign in.']);

            return back();
        }

        $account->delete();
        AccountEvent::record($user, 'social.disconnected', ['provider' => $provider]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $this->label($provider).' disconnected.']);

        return back();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, SocialAccount::enabled(), true)) {
            return $this->failed('That sign-in option isn’t available yet.');
        }

        // Signed in already: either finishing a connect from Settings, or
        // a stray visit, which goes to the dashboard.
        $connecting = null;
        if ($request->user() !== null) {
            $connecting = $request->session()->pull('oauth.connect');
            if (! is_array($connecting) || ($connecting['provider'] ?? null) !== $provider) {
                return redirect()->intended(Fortify::redirects('login'));
            }
        }

        try {
            $account = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            // A stale state (the back button, a second tab, an expired
            // session) is the visitor's to retry, not a bug to report. Nor
            // is a callback without a code: they pressed Cancel on the
            // provider's screen, or opened the address on its own.
            if (! $e instanceof InvalidStateException && $request->filled('code')) {
                report($e);
            }

            return $connecting !== null
                ? $this->toSettings($connecting, 'error', 'We couldn’t connect '.$this->label($provider).'. Try again?')
                : $this->failed('We couldn’t sign you in with '.$this->label($provider).'. Try again?');
        }

        if ($connecting !== null) {
            return $this->link($request->user(), $provider, $account, $connecting);
        }

        $email = Str::lower(trim((string) $account->getEmail()));
        if ($email === '' || ! $this->emailVerified($provider, $account)) {
            return $this->failed('Your '.$this->label($provider).' account needs a verified email address.');
        }

        $user = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', (string) $account->getId())
            ->first()?->user;

        // A package can turn it down, say when the company requires SSO.
        $existing = $user ?? User::query()->whereRaw('lower(email) = ?', [$email])->first();
        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            if (($refused = $hooks->refuseSocialSignIn($request, $existing, $email)) !== null) {
                return $this->failed($refused);
            }
        }

        if ($user === null) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            $user = DB::transaction(function () use ($user, $account, $provider, $email): User {
                $user ??= $this->createUser($account, $email);
                $user->socialAccounts()->create([
                    'provider' => $provider,
                    'provider_id' => (string) $account->getId(),
                    'email' => $email,
                ]);

                // The provider vouches for the address.
                if ($user->email_verified_at === null && Str::lower($user->email) === $email) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                return $user;
            });
        }

        if ($user->suspended_at !== null) {
            return $this->failed('This account has been suspended. If you think that’s a mistake, email '.config('mail.support.address').'.');
        }

        // No profile picture yet: use theirs from Google or GitHub.
        ProviderAvatar::apply($user, $account->getAvatar());

        // A Google account on a company's domain: kept until the sign-in
        // completes (after the code prompt, if there is one), when
        // GoogleTeamAccess acts on it.
        $hd = $provider === 'google' ? GoogleTeamAccess::verifiedDomain($account) : null;
        $hd !== null
            ? $request->session()->put(GoogleTeamAccess::PENDING_HD, $hd)
            : $request->session()->forget(GoogleTeamAccess::PENDING_HD);

        // Same as a password sign-in: the code prompt comes first.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true, RecordAccountEvents::VIA => $provider]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(Fortify::redirects('login'));
    }

    /**
     * Connect the provider account to the signed-in user, unless it already
     * belongs to someone else or they have one connected for it.
     *
     * @param  array{provider: string, return?: string}  $connecting
     */
    private function link(User $user, string $provider, ProviderUser $account, array $connecting): RedirectResponse
    {
        $label = $this->label($provider);
        $existing = SocialAccount::query()->where('provider', $provider)->where('provider_id', (string) $account->getId())->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            return $this->toSettings($connecting, 'error', "That {$label} account is connected to another ServerOS account.");
        }
        if ($existing === null && $user->socialAccounts()->where('provider', $provider)->exists()) {
            return $this->toSettings($connecting, 'error', "You already have a {$label} account connected. Disconnect it first.");
        }

        if ($existing === null) {
            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => (string) $account->getId(),
                'email' => Str::lower(trim((string) $account->getEmail())) ?: null,
            ]);
            AccountEvent::record($user, 'social.connected', ['provider' => $provider]);
        }

        return $this->toSettings($connecting, 'success', "{$label} connected. You can sign in with it now.");
    }

    /** @param  array{provider: string, return?: string}  $connecting */
    private function toSettings(array $connecting, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->to($connecting['return'] ?? route('security.edit'));
    }

    private function backToSettings(Request $request, string $type, string $message): RedirectResponse
    {
        return $this->toSettings(['provider' => '', 'return' => $this->settingsUrl($request)], $type, $message);
    }

    /** Back to whichever Security page they came from, on our own domain. */
    private function settingsUrl(Request $request): string
    {
        $previous = (string) url()->previous();
        $host = (string) parse_url($previous, PHP_URL_HOST);
        $domain = (string) config('app.domain');

        return $host !== '' && ($host === $domain || str_ends_with($host, '.'.$domain)) && str_contains($previous, '/settings/security')
            ? $previous
            : route('security.edit');
    }

    /** A new account with its own workspace, as signing up does. */
    private function createUser(ProviderUser $account, string $email): User
    {
        $name = trim((string) ($account->getName() ?: $account->getNickname() ?: Str::before($email, '@')));

        $user = User::create([
            'name' => Str::limit($name, 255, ''),
            'email' => $email,
            // Never used: they can set a real one with "Forgot your password?".
            'password' => Str::random(64),
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        // The packages hear about it, as with the sign-up form.
        $extensions = app(Extensions::class);
        $extensions->signedUp(request(), $user);

        $this->createWorkspace->create($user);

        $from = $extensions->signUpSource($user);
        TeamPush::send('New signup', 'A new user signed up with a social login'.($from !== null ? " from {$from}" : ''), TeamPush::admin('admin.users'), 'wave');

        // A new account has just started its trial: celebrate it.
        request()->session()->put('celebrate_trial', true);

        return $user;
    }

    /**
     * GitHub only hands over a primary, verified address; Google says
     * whether the one it gives is verified.
     */
    private function emailVerified(string $provider, ProviderUser $account): bool
    {
        if ($provider !== 'google') {
            return true;
        }

        $raw = method_exists($account, 'getRaw') ? $account->getRaw() : [];

        return ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true;
    }

    private function label(string $provider): string
    {
        return $provider === 'github' ? 'GitHub' : 'Google';
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('oauth_error', $message);
    }
}
