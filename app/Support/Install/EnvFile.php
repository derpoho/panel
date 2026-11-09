<?php

namespace App\Support\Install;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use RuntimeException;
use Throwable;

/**
 * The settings file the installer writes (App\Support\Install): the app's
 * .env, or in the Docker image the file it points at in storage. Only the
 * keys the installer owns change; every other line, comment and blank
 * stays as it was. Written to a new file that then takes the old one's
 * place, so a crash never leaves half a file, and kept readable by its
 * owner alone (0600) where the system allows.
 */
final class EnvFile
{
    public function __construct(private ?string $path = null) {}

    /** Where it is: a symlink's target, so the link itself stays. */
    public function path(): string
    {
        $path = $this->path ?? app()->environmentFilePath();

        if (is_link($path)) {
            $target = (string) readlink($path);
            $path = str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
        }

        return $path;
    }

    /** Whether it can be written: the file, or its folder when there's none yet. */
    public function writable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_dir(dirname($path)) && is_writable(dirname($path));
    }

    /**
     * What it sets now.
     *
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }
    }

    public function get(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    /**
     * Whether the key comes from the environment the panel runs in (Docker's
     * settings, a systemd unit's) rather than this file, so writing it here
     * would change nothing.
     */
    public function managedElsewhere(string $key): bool
    {
        $environment = Env::getRepository()->get($key);
        if ($environment === null) {
            return false;
        }

        $values = $this->values();

        return ! array_key_exists($key, $values) || (string) $values[$key] !== $environment;
    }

    /**
     * Set these keys, keeping every other line. A key already in the file
     * is changed where it is (its last line, the one that counts); new ones
     * go at the end.
     *
     * @param  array<string, string|null>  $values
     */
    public function set(array $values): void
    {
        $path = $this->path();
        $lines = is_file($path) ? preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) : [];
        $lines = $lines === false ? [] : $lines;
        // A trailing newline reads as one empty last line: put it back at the end.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $added = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException("{$key} isn't a setting name.");
            }
            $line = $key.'='.self::quote($value ?? '');

            $found = null;
            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/', $existing) === 1) {
                    $found = $index;
                }
            }

            if ($found === null) {
                $added[] = $line;
            } else {
                $lines[$found] = $line;
            }
        }

        if ($added !== []) {
            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }
            array_push($lines, ...$added);
        }

        $this->write(implode("\n", $lines)."\n");
    }

    /**
     * A value as the file needs it: bare when that's safe, in single quotes
     * (taken as written) otherwise, and in double quotes with escapes only
     * when it has a single quote of its own.
     */
    public static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new RuntimeException('A setting can\'t have a line break in it.');
        }
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@+,-]+$/', $value) === 1) {
            return $value;
        }
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }

    private function write(string $contents): void
    {
        $path = $this->path();
        $directory = dirname($path);

        // Atomic where the folder allows: a new file takes the old one's
        // place. Not over a file someone else owns, though: the new one
        // would be ours alone, and they couldn't read their settings.
        if (is_dir($directory) && is_writable($directory) && $this->ours($path)) {
            $temporary = @tempnam($directory, '.env.');
            if ($temporary !== false) {
                @chmod($temporary, 0600);
                if (@file_put_contents($temporary, $contents) === strlen($contents) && @rename($temporary, $path)) {
                    @chmod($path, 0600);

                    return;
                }
                @unlink($temporary);
            }
        }

        // Otherwise in place, keeping its owner and permissions.
        $new = ! is_file($path);
        if (@file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException("Couldn't write {$path}.");
        }
        if ($new) {
            @chmod($path, 0600);
        }
    }

    /** Whether the file is this process's own, or isn't there yet. */
    private function ours(string $path): bool
    {
        return ! is_file($path) || ! function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
    }
}
