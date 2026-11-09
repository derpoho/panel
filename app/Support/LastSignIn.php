<?php

namespace App\Support;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * How this browser last signed in (password, passkey, Google or GitHub),
 * so the sign-in page can mark it "Last used". Only the way in is kept,
 * never who: it stays after signing out, since that's when it helps.
 */
final class LastSignIn
{
    public const COOKIE = 'last_sign_in';

    public const DAYS = 400;

    private const METHODS = ['password', 'passkey', 'google', 'github', 'sso'];

    /** Called with the method a sign-in just used; a recovery code counts as the password. */
    public static function remember(string $method): void
    {
        $method = $method === 'recovery_code' ? 'password' : $method;

        if (in_array($method, self::METHODS, true)) {
            Cookie::queue(self::COOKIE, $method, self::DAYS * 24 * 60);
        }
    }

    /** The method to mark on the sign-in page, if it's still on offer there. */
    public static function fromRequest(Request $request): ?string
    {
        $method = $request->cookie(self::COOKIE);

        return match (true) {
            ! is_string($method) || ! in_array($method, self::METHODS, true) => null,
            in_array($method, ['google', 'github'], true) && ! in_array($method, SocialAccount::enabled(), true) => null,
            default => $method,
        };
    }
}
