<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The service console runs commands on real machines, so it needs more than
 * a login: two-factor authentication on the account, and a fresh 2FA code
 * to unlock it. An unlock lasts 15 minutes for this session.
 */
class ConsoleLock
{
    public const MINUTES = 15;

    private const KEY = 'console.unlocked_until';

    public function __construct(
        private readonly Session $session,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function requiresSetup(User $user): bool
    {
        return ! $user->hasEnabledTwoFactorAuthentication();
    }

    public function unlockedUntil(): ?Carbon
    {
        $until = $this->session->get(self::KEY);

        return is_int($until) && $until > now()->getTimestamp()
            ? Carbon::createFromTimestamp($until)
            : null;
    }

    public function isUnlocked(User $user): bool
    {
        return ! $this->requiresSetup($user) && $this->unlockedUntil() !== null;
    }

    /**
     * Unlock with a code from the user's authenticator app.
     */
    public function unlock(User $user, string $code): bool
    {
        if ($this->requiresSetup($user) || $user->two_factor_secret === null) {
            return false;
        }

        $valid = $this->provider->verify(decrypt($user->two_factor_secret), $code);
        if ($valid) {
            $this->session->put(self::KEY, now()->addMinutes(self::MINUTES)->getTimestamp());
        }

        return $valid;
    }

    public function lock(): void
    {
        $this->session->forget(self::KEY);
    }
}
