<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile: proof a person, not a script, filled in the sign-up
 * form. The widget on the page gives a one-time token; Cloudflare says
 * whether it's good. Off until both keys are set.
 */
class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return self::enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public function passes(?string $token, ?string $ip): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(self::VERIFY_URL, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            // Cloudflare unreachable: don't lock everyone out of signing up.
            Log::warning('Turnstile check failed to run', ['error' => $e->getMessage()]);

            return true;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
