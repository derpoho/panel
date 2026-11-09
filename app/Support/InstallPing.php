<?php

namespace App\Support;

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The install ping: once a day a self-hosted install tells serveros.com it's
 * still running, so we can count installs. It's anonymous and on unless
 * SERVEROS_TELEMETRY=false. serveros.com itself never sends it.
 *
 * It sends only what payload() builds: a random id made for this install
 * (kept in the install_identity table, so it survives rebuilding the
 * containers), the panel's version, the edition, whether a workspace here
 * has a ServerOS Business license, and four counts. No names, emails,
 * domains, hostnames, addresses or anything else that says who or where the
 * install is. A package can add a number or a yes/no through
 * Extensions::addInstallStat, and nothing else. `php artisan install:ping
 * --show` prints exactly what would be sent. See docs/self-hosting.md.
 */
final class InstallPing
{
    public const string DEFAULT_URL = 'https://serveros.com/api/installs/ping';

    /** The ping's own fields, in the order they're sent. Package stats follow. */
    public const array FIELDS = ['install_id', 'version', 'edition', 'licensed', 'workspaces', 'users', 'machines', 'active_machines'];

    /** The ones a package answers (addInstallStat), false or 0 until one does. */
    public const array PACKAGE_FIELDS = ['licensed'];

    /** Whether this install sends the ping on its own: self-hosted, and not turned off. */
    public static function enabled(): bool
    {
        return Edition::selfHosted() && (bool) config('serveros.telemetry.enabled', true);
    }

    public static function url(): string
    {
        return (string) (config('serveros.telemetry.url') ?: self::DEFAULT_URL);
    }

    /**
     * This install's random id, made the first time it's asked for (the
     * migration makes it too) and kept in the database from then on.
     */
    public static function installId(): string
    {
        DB::table('install_identity')->insertOrIgnore(['id' => 1, 'install_id' => (string) Str::uuid(), 'created_at' => now()]);

        return (string) DB::table('install_identity')->where('id', 1)->value('install_id');
    }

    /**
     * When the ping goes each day, as HH:MM: a minute picked from the
     * install's id, so installs don't all send at once. Read when the
     * schedule is defined, so it can't touch anything: before the database
     * is there (a first migrate) it falls back to a minute from APP_KEY.
     */
    public static function dailyAt(): string
    {
        try {
            $seed = (string) DB::table('install_identity')->where('id', 1)->value('install_id');
        } catch (Throwable) {
            $seed = '';
        }
        $seed = $seed !== '' ? $seed : (string) config('app.key');

        $minute = hexdec(substr(hash('sha256', $seed), 0, 8)) % 1440;

        return sprintf('%02d:%02d', intdiv((int) $minute, 60), $minute % 60);
    }

    /** The panel's version, as the image was built with it (/app/VERSION), or dev. */
    public static function version(): string
    {
        $file = base_path('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? Str::limit($version, 40, '') : 'dev';
    }

    /**
     * Exactly what the ping sends, and all of it.
     *
     * @return array<string, string|int|bool>
     */
    public static function payload(): array
    {
        return [
            'install_id' => self::installId(),
            'version' => self::version(),
            'edition' => (string) config('serveros.edition'),
            // Whether any workspace here has a valid ServerOS Business
            // license: a package answers through its install stat.
            'licensed' => false,
            'workspaces' => Workspace::query()->count(),
            'users' => User::query()->count(),
            'machines' => Machine::query()->count(),
            'active_machines' => Machine::query()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            ...app(Extensions::class)->installStats(),
        ];
    }

    /**
     * Send it now. Never throws and never waits long: a failure is logged
     * and returned as a message, and the panel carries on.
     *
     * @return string|null why it didn't go, or null when it did
     */
    public static function send(): ?string
    {
        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withUserAgent('ServerOS install ping')
                ->acceptJson()
                ->asJson()
                ->post(self::url(), self::payload());

            if ($response->successful()) {
                return null;
            }

            $error = self::url()." answered {$response->status()}.";
        } catch (Throwable $e) {
            $error = "Couldn't send it: {$e->getMessage()}";
        }

        logger()->warning("Install ping: {$error}");

        return $error;
    }
}
