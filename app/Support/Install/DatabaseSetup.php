<?php

namespace App\Support\Install;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * The installer's database step: whether the panel's database is there and
 * has its tables, trying settings before they're saved (with errors in
 * plain words, never the password), and what they become in .env.
 */
final class DatabaseSetup
{
    /** What someone picks: MySQL and MariaDB are one choice, told apart once connected. */
    public const LABELS = ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlite' => 'SQLite'];

    public const EXTENSIONS = ['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite'];

    public const EXTENSIONS_TO_DRIVERS = ['pdo_pgsql' => 'pgsql', 'pdo_mysql' => 'mysql', 'pdo_sqlite' => 'sqlite'];

    /** The settings keys this step owns. */
    public const KEYS = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /** Only for the current request: settings being tried, or just saved. */
    private const CONNECTION = 'serveros_install';

    public function __construct(private Migrator $migrator) {}

    /**
     * The database the panel uses now.
     *
     * @return array{connected: bool, error: string|null, driver: string|null, pending: int, ready: bool}
     */
    public function status(): array
    {
        $name = (string) config('database.default');
        $driver = config("database.connections.{$name}.driver");
        $driver = is_string($driver) ? $driver : null;

        try {
            DB::connection($name)->getPdo();
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => self::plainError($e, (array) config("database.connections.{$name}")), 'driver' => $driver, 'pending' => 0, 'ready' => false];
        }

        $pending = count($this->pending($name));

        return ['connected' => true, 'error' => null, 'driver' => $driver, 'pending' => $pending, 'ready' => $pending === 0];
    }

    /**
     * The migrations not run yet on a connection, by name.
     *
     * @return list<string>
     */
    public function pending(?string $connection = null): array
    {
        return $this->migrator->usingConnection($connection ?? (string) config('database.default'), function (): array {
            $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
            $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];

            return array_values(array_diff(array_keys($files), $ran));
        });
    }

    /**
     * Try settings: null when they connect, otherwise what's wrong, in plain
     * words. MySQL that turns out to be MariaDB comes back as mariadb.
     *
     * @param  array{driver: string, host?: string|null, port?: string|int|null, database: string, username?: string|null, password?: string|null}  $settings
     * @return array{error: string|null, driver: string}
     */
    public function test(array $settings): array
    {
        $driver = $settings['driver'];
        if (! extension_loaded(self::EXTENSIONS[$driver])) {
            return ['error' => 'PHP can’t talk to '.self::LABELS[$driver].' yet: install its '.self::EXTENSIONS[$driver].' extension, then restart PHP.', 'driver' => $driver];
        }

        if ($driver === 'sqlite') {
            $error = self::prepareSqlite($settings['database']);
            if ($error !== null) {
                return ['error' => $error, 'driver' => $driver];
            }
        }

        $config = $this->connectionConfig($settings);
        config(['database.connections.'.self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);

        try {
            $connection = DB::connection(self::CONNECTION);
            $connection->getPdo();
            if ($driver === 'mysql' && str_contains(strtolower((string) $connection->selectOne('select version() as version')?->version), 'mariadb')) {
                $driver = 'mariadb';
            }

            return ['error' => null, 'driver' => $driver];
        } catch (Throwable $e) {
            return ['error' => self::plainError($e, $config), 'driver' => $driver];
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    /**
     * Use these settings for the rest of this request, as the next one will
     * from .env.
     *
     * @param  array{driver: string, host?: string|null, port?: string|int|null, database: string, username?: string|null, password?: string|null}  $settings
     */
    public function use(array $settings): void
    {
        config([
            'database.connections.'.self::CONNECTION => $this->connectionConfig($settings),
            'database.default' => self::CONNECTION,
        ]);
        DB::purge(self::CONNECTION);
    }

    /**
     * What they are in .env.
     *
     * @param  array{driver: string, host?: string|null, port?: string|int|null, database: string, username?: string|null, password?: string|null}  $settings
     * @return array<string, string>
     */
    public static function envValues(array $settings): array
    {
        if ($settings['driver'] === 'sqlite') {
            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $settings['database'], 'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => ''];
        }

        return [
            'DB_CONNECTION' => $settings['driver'],
            'DB_HOST' => (string) ($settings['host'] ?? ''),
            'DB_PORT' => (string) ($settings['port'] ?? ''),
            'DB_DATABASE' => $settings['database'],
            'DB_USERNAME' => (string) ($settings['username'] ?? ''),
            'DB_PASSWORD' => (string) ($settings['password'] ?? ''),
        ];
    }

    /**
     * @param  array{driver: string, host?: string|null, port?: string|int|null, database: string, username?: string|null, password?: string|null}  $settings
     * @return array<string, mixed>
     */
    private function connectionConfig(array $settings): array
    {
        $driver = $settings['driver'];
        $base = (array) config("database.connections.{$driver}");
        unset($base['url']);

        if ($driver === 'sqlite') {
            return [...$base, 'driver' => 'sqlite', 'database' => $settings['database']];
        }

        return [
            ...$base,
            'driver' => $driver,
            'host' => $settings['host'] ?? '127.0.0.1',
            'port' => $settings['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => $settings['database'],
            'username' => $settings['username'] ?? '',
            'password' => $settings['password'] ?? '',
            'options' => [...(array) ($base['options'] ?? []), PDO::ATTR_TIMEOUT => 5],
        ];
    }

    /** A new SQLite database is an empty file: made here, if the folder allows. */
    private static function prepareSqlite(string $path): ?string
    {
        if (! str_starts_with($path, '/')) {
            return 'Give the full path to the database file, starting with /.';
        }
        if (is_file($path)) {
            return is_writable($path) ? null : "PHP can’t write to {$path}. Give the web server’s user that file and its folder.";
        }
        if (! is_dir(dirname($path))) {
            return 'There’s no folder '.dirname($path).'. Make it first, or pick another path.';
        }
        if (! is_writable(dirname($path)) || @touch($path) === false) {
            return 'PHP can’t make a file in '.dirname($path).'. Give the web server’s user that folder.';
        }

        return null;
    }

    /**
     * What went wrong connecting, in plain words: never the password, and
     * not the driver's own message unless nothing better fits.
     *
     * @param  array<string, mixed>  $config
     */
    public static function plainError(Throwable $e, array $config): string
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $driver = (string) ($config['driver'] ?? '');
        $where = ($config['host'] ?? '127.0.0.1').':'.($config['port'] ?? '');
        $database = (string) ($config['database'] ?? '');

        return match (true) {
            str_contains($lower, 'could not find driver') => 'PHP can’t talk to '.(self::LABELS[$driver] ?? $driver).' yet: install its '.(self::EXTENSIONS[$driver] ?? 'PDO').' extension, then restart PHP.',
            $driver === 'sqlite' && (str_contains($lower, 'does not exist') || str_contains($lower, 'unable to open')) => "Couldn’t open the database file {$database}.",
            str_contains($lower, 'password authentication failed') || str_contains($lower, 'access denied') || str_contains($lower, '[28p01]') || str_contains($lower, '[1045]') => 'The database turned down that username and password.',
            str_contains($lower, 'does not exist') || str_contains($lower, 'unknown database') || str_contains($lower, '[1049]') => "There’s no database called {$database} there. Make it first (an empty one is fine), then try again.",
            str_contains($lower, 'refused') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'could not translate host') || str_contains($lower, 'name or service not known') || str_contains($lower, 'getaddrinfo') || str_contains($lower, 'no route to host') || str_contains($lower, 'connection to server') || str_contains($lower, '[2002]') => "Couldn’t reach a database at {$where}. Check the host and port, that it’s running, and that it accepts connections from this server.",
            default => 'Couldn’t connect: '.self::scrub(strtok($message, "\n") ?: $message, $config),
        };
    }

    /** @param  array<string, mixed>  $config */
    private static function scrub(string $message, array $config): string
    {
        $password = (string) ($config['password'] ?? '');
        $message = $password !== '' ? str_replace($password, '••••', $message) : $message;

        // The SQL Laravel adds after the driver's message says nothing useful here.
        return trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $message));
    }
}
