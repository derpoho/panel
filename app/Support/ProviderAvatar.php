<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Someone who signs in with Google or GitHub and has no profile picture
 * gets theirs from there. It's copied to our storage, not linked: Google's
 * links expire, and the image shouldn't load from Google on every page.
 */
final class ProviderAvatar
{
    /** Where Google and GitHub serve profile pictures from. */
    private const array HOSTS = ['googleusercontent.com', 'githubusercontent.com'];

    private const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private const int MAX_BYTES = 5 * 1024 * 1024;

    public static function apply(User $user, ?string $url): void
    {
        if ($user->avatar_path !== null || ! self::allowed($url)) {
            return;
        }

        try {
            $response = Http::timeout(5)->withoutRedirecting()->get(self::larger((string) $url));
        } catch (Throwable) {
            return;
        }

        $type = strtolower(trim(Str::before((string) $response->header('Content-Type'), ';')));
        $body = $response->body();
        if (! $response->successful() || ! isset(self::TYPES[$type]) || $body === '' || strlen($body) > self::MAX_BYTES) {
            return;
        }

        $path = 'avatars/'.Str::random(40).'.'.self::TYPES[$type];
        Storage::disk('public')->put($path, $body);

        // Set by hand while this was downloading: theirs wins.
        if (User::query()->whereKey($user->id)->whereNull('avatar_path')->update(['avatar_path' => $path]) === 0) {
            Storage::disk('public')->delete($path);

            return;
        }
        $user->avatar_path = $path;
    }

    private static function allowed(?string $url): bool
    {
        if ($url === null || ! str_starts_with($url, 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Both hand over small ones by default (Google's are 96px). */
    private static function larger(string $url): string
    {
        if (str_contains($url, 'googleusercontent.com')) {
            return preg_replace('/=s\d+(-c)?$/', '=s256-c', $url) ?? $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'s=256';
    }
}
