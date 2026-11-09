<?php

namespace App\Support\Audit;

/**
 * A browser's user agent as people say it: "Chrome on macOS". Just the
 * common ones; anything else reads as null, and the raw string is still
 * there for staff.
 */
final class Device
{
    public static function label(?string $agent): ?string
    {
        if ($agent === null || $agent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') || str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser === null || $os === null) {
            return $browser ?? $os;
        }
        $label = __(':browser on :os', ['browser' => $browser, 'os' => $os]);

        return is_string($label) ? $label : "{$browser} on {$os}";
    }
}
