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

final class InstallPing
{
    public const string DEFAULT_URL = 'https://serveros.com/api/installs/ping';

    public const array FIELDS = ['install_id', 'version', 'edition', 'licensed', 'workspaces', 'users', 'machines', 'active_machines'];

    public const array PACKAGE_FIELDS = ['licensed'];

    public static function enabled(): bool
    {
        return Edition::selfHosted() && (bool) config('serveros.telemetry.enabled', true);
    }

    public static function url(): string
    {
        return (string) (config('serveros.telemetry.url') ?: self::DEFAULT_URL);
    }

    public static function installId(): string
    {
        DB::table('install_identity')->insertOrIgnore(['id' => 1, 'install_id' => (string) Str::uuid(), 'created_at' => now()]);

        return (string) DB::table('install_identity')->where('id', 1)->value('install_id');
    }

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

    public static function version(): string
    {
        $file = (string) config('serveros.updates.version_file', base_path('VERSION'));
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? Str::limit($version, 40, '') : 'dev';
    }

    /** @return array<string, string|int|bool> */
    public static function payload(): array
    {
        return [
            'install_id' => self::installId(),
            'version' => self::version(),
            'edition' => (string) config('serveros.edition'),
            'licensed' => false,
            'workspaces' => Workspace::query()->count(),
            'users' => User::query()->count(),
            'machines' => Machine::query()->count(),
            'active_machines' => Machine::query()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            ...app(Extensions::class)->installStats(),
        ];
    }

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
