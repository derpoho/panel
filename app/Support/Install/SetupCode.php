<?php

namespace App\Support\Install;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The one-time code that opens the installer, so only whoever runs the
 * server can set it up: made once, at random, and shown only where the
 * server's owner looks (the install script's last lines, the web
 * container's log at start, `php artisan install:code`). Kept in storage,
 * not the database, since a new install has none yet: a hash to check
 * against, and a copy encrypted with APP_KEY so those places can show it
 * again. Gone once the install is set up.
 */
final class SetupCode
{
    /** No 0/O, 1/I/L: easy to read out and type. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Three groups of four: about 59 bits. */
    private const LENGTH = 12;

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? self::directory().'/setup-code.json';
    }

    /** Where the installer keeps what it needs before there's a database. */
    public static function directory(): string
    {
        $directory = config('serveros.install.path');

        return is_string($directory) && $directory !== '' ? rtrim($directory, '/') : storage_path('app/private/install');
    }

    public function pending(): bool
    {
        return $this->stored() !== null;
    }

    /** The code, made now if there isn't one (or the old one can't be read back). */
    public function current(): string
    {
        $stored = $this->stored();
        if ($stored !== null) {
            try {
                $code = Crypt::decryptString($stored['code']);
                if (Hash::check(self::normalize($code), $stored['hash'])) {
                    return self::format($code);
                }
            } catch (Throwable) {
                // APP_KEY changed since it was made: a new one below.
            }
        }

        return $this->regenerate();
    }

    /** A new code, replacing the old one (and signing out anyone who used it). */
    public function regenerate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $directory = dirname($this->path());
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $contents = (string) json_encode([
            'hash' => Hash::make($code),
            'code' => Crypt::encryptString($code),
            'created_at' => now()->toIso8601String(),
        ]);
        $temporary = $this->path().'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, $contents);
        @chmod($temporary, 0600);
        rename($temporary, $this->path());

        return self::format($code);
    }

    public function matches(?string $given): bool
    {
        $stored = $this->stored();
        $given = self::normalize((string) $given);

        return $stored !== null && strlen($given) === self::LENGTH && Hash::check($given, $stored['hash']);
    }

    /**
     * Stands for this code in a session that entered it, so making a new
     * one signs those sessions out. Not the code, nor anything it can be
     * worked out from.
     */
    public function fingerprint(): ?string
    {
        $stored = $this->stored();

        return $stored === null ? null : hash('sha256', $stored['hash']);
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    /** As shown: ABCD-EFGH-JKMN. */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 4));
    }

    /** As typed, any case, with or without the dashes and spaces. */
    public static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /** @return array{hash: string, code: string}|null */
    private function stored(): ?array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return null;
        }

        $stored = json_decode((string) @file_get_contents($path), true);

        return is_array($stored) && is_string($stored['hash'] ?? null) && is_string($stored['code'] ?? null)
            ? ['hash' => $stored['hash'], 'code' => $stored['code']]
            : null;
    }
}
