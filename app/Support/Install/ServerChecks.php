<?php

namespace App\Support\Install;

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The installer's server check: what PHP needs, which folders it must be
 * able to write, and how the queue and the scheduler run, each with a
 * plain fix. "fail" stops the install, "warn" doesn't.
 */
final class ServerChecks
{
    public const MIN_PHP = '8.3.0';

    /** The extensions the panel can't run without. */
    public const EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip'];

    public function __construct(private EnvFile $env) {}

    /**
     * @return list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string, fix: string|null, command: string|null}>
     */
    public function run(): array
    {
        $docker = Installer::docker();
        $php = 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        $checks = [];

        $checks[] = version_compare(PHP_VERSION, self::MIN_PHP, '>=')
            ? self::ok('php', 'PHP', PHP_VERSION.'.')
            : self::fail('php', 'PHP', PHP_VERSION.' is too old: ServerOS needs '.self::MIN_PHP.' or newer.', 'Install PHP 8.4 (on Ubuntu, from the ondrej/php PPA), then point the web server at it.');

        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));
        $checks[] = $missing === []
            ? self::ok('extensions', 'PHP extensions', 'All there.')
            : self::fail('extensions', 'PHP extensions', 'Missing: '.implode(', ', $missing).'.', 'Install them, then restart PHP. On Ubuntu:', 'apt install '.implode(' ', array_unique(array_map(fn (string $extension) => "{$php}-".self::package($extension), $missing))));

        $drivers = array_values(array_filter(['pdo_pgsql', 'pdo_mysql', 'pdo_sqlite'], fn (string $extension) => extension_loaded($extension)));
        $checks[] = $drivers !== []
            ? self::ok('drivers', 'Database drivers', 'PHP can talk to '.implode(', ', array_map(fn (string $driver) => DatabaseSetup::LABELS[DatabaseSetup::EXTENSIONS_TO_DRIVERS[$driver]], $drivers)).'.')
            : self::fail('drivers', 'Database drivers', 'PHP can\'t talk to any database yet.', "Install one ({$php}-mysql for MySQL or MariaDB, {$php}-sqlite3 for SQLite), then restart PHP. For PostgreSQL, on Ubuntu:", "apt install {$php}-pgsql");

        $folders = ['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'];
        $unwritable = array_values(array_filter($folders, fn (string $folder) => ! self::writableFolder(base_path($folder))));
        $checks[] = $unwritable === []
            ? self::ok('folders', 'Folders', 'storage and bootstrap/cache can be written to.')
            : self::fail('folders', 'Folders', 'PHP can\'t write to '.implode(', ', $unwritable).'.', 'Give the web server\'s user those folders, for example:', 'chown -R www-data:www-data '.base_path('storage').' '.base_path('bootstrap/cache'));

        $checks[] = match (true) {
            $this->env->writable() => self::ok('settings', 'Settings file', 'The installer can save its settings to '.$this->env->path().'.'),
            $docker => self::warn('settings', 'Settings file', 'The installer can\'t save settings in this container.', 'Make sure the storage volume is mounted, then restart the panel.'),
            default => self::warn('settings', 'Settings file', 'The installer can\'t write '.$this->env->path().', so it can\'t save the database, address or email settings.', 'Let the web server\'s user write it (or set them yourself and come back):', 'chown www-data '.$this->env->path()),
        };

        $queue = (string) config('queue.default');
        $checks[] = match (true) {
            $docker => self::ok('queue', 'Queue', 'Runs in its own container (queue).'),
            $queue === 'sync' => self::warn('queue', 'Queue', 'QUEUE_CONNECTION is sync, so background work runs inside page loads and emails can make pages slow.', 'Set QUEUE_CONNECTION=database (or redis) in .env, and keep a worker running as a systemd service:', 'php artisan queue:work --tries=3'),
            default => self::warn('queue', 'Queue', 'Background work (jobs for your machines, emails, alerts) needs a worker.', 'Keep one running as a systemd service, from '.base_path().':', 'php artisan queue:work --tries=3'),
        };

        $checks[] = match (true) {
            $this->schedulerRunning() => self::ok('scheduler', 'Scheduler', $docker ? 'Runs in its own container (scheduler).' : 'It\'s running.'),
            $docker => self::ok('scheduler', 'Scheduler', 'Runs in its own container (scheduler).'),
            default => self::warn('scheduler', 'Scheduler', 'Monitors, backups and workflows run on a timer.', 'Add this to the web server user\'s crontab:', '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1'),
        };

        return $checks;
    }

    /** @param  list<array{status: string}>  $checks */
    public static function passed(array $checks): bool
    {
        return collect($checks)->doesntContain('status', 'fail');
    }

    private function schedulerRunning(): bool
    {
        try {
            $beat = Cache::get(SchedulerHeartbeat::KEY);

            return is_numeric($beat) && (int) $beat > now()->subMinutes(3)->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    private static function writableFolder(string $path): bool
    {
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return is_dir($path) && is_writable($path);
    }

    private static function package(string $extension): string
    {
        return match ($extension) {
            'dom', 'xml', 'tokenizer' => 'xml',
            'pdo', 'ctype', 'fileinfo' => 'common',
            default => $extension,
        };
    }

    /** @return array{key: string, label: string, status: 'ok', detail: string, fix: null, command: null} */
    private static function ok(string $key, string $label, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'ok', 'detail' => $detail, 'fix' => null, 'command' => null];
    }

    /** @return array{key: string, label: string, status: 'warn', detail: string, fix: string, command: string|null} */
    private static function warn(string $key, string $label, string $detail, string $fix, ?string $command = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => $detail, 'fix' => $fix, 'command' => $command];
    }

    /** @return array{key: string, label: string, status: 'fail', detail: string, fix: string, command: string|null} */
    private static function fail(string $key, string $label, string $detail, string $fix, ?string $command = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'fail', 'detail' => $detail, 'fix' => $fix, 'command' => $command];
    }
}
