<?php

namespace App\Support\Deploys;

use App\Models\Machine;

/**
 * The database an app keeps its data in, on the same machine, so a deploy
 * of the app snapshots it too: the one chosen for the app, or else the one
 * its settings point at (DATABASE_URL, DB_HOST and DB_PORT, PGHOST…).
 */
final class AppDatabase
{
    /** Settings holding a database's address, as a URL. */
    private const URLS = ['DATABASE_URL', 'DB_URL', 'POSTGRES_URL', 'POSTGRESQL_URL', 'PG_URL', 'MYSQL_URL', 'MARIADB_URL'];

    /** Settings holding it as a host and a port, with the port when unset. */
    private const HOSTS = [
        ['DB_HOST', 'DB_PORT', null],
        ['PGHOST', 'PGPORT', 5432],
        ['POSTGRES_HOST', 'POSTGRES_PORT', 5432],
        ['MYSQL_HOST', 'MYSQL_PORT', 3306],
        ['MARIADB_HOST', 'MARIADB_PORT', 3306],
    ];

    /**
     * The machine's databases ServerOS can snapshot: the ones it dumps with
     * their own tools.
     *
     * @return list<array<string, mixed>> inventory entries
     */
    public static function candidates(Machine $machine): array
    {
        return array_values(array_filter(
            $machine->inventory['services'] ?? [],
            fn ($s) => is_array($s) && isset($s['key'])
                && ($s['details']['managed'] ?? null) === 'true'
                && in_array($s['details']['backup'] ?? null, ['pg_dumpall', 'mysqldump'], true),
        ));
    }

    /**
     * The database the app's settings point at, if it's one of them.
     *
     * @param  array<string, mixed>  $env
     * @return array<string, mixed>|null
     */
    public static function detect(Machine $machine, array $env): ?array
    {
        $addresses = [];
        foreach (self::URLS as $name) {
            $url = is_string($env[$name] ?? null) ? parse_url($env[$name]) : false;
            if (is_array($url) && isset($url['host'])) {
                $scheme = strtolower($url['scheme'] ?? '');
                $addresses[] = [$url['host'], $url['port'] ?? (str_contains($scheme, 'postgres') ? 5432 : (str_contains($scheme, 'mysql') || str_contains($scheme, 'maria') ? 3306 : null))];
            }
        }
        foreach (self::HOSTS as [$host, $port, $default]) {
            if (is_string($env[$host] ?? null) && $env[$host] !== '') {
                $default ??= match (strtolower((string) ($env['DB_CONNECTION'] ?? ''))) {
                    'pgsql', 'postgres', 'postgresql' => 5432,
                    'mysql', 'mariadb' => 3306,
                    default => null,
                };
                $addresses[] = [$env[$host], is_numeric($env[$port] ?? null) ? (int) $env[$port] : $default];
            }
        }

        foreach ($addresses as [$host, $port]) {
            foreach (self::candidates($machine) as $candidate) {
                $name = ltrim((string) ($candidate['name'] ?? ''), '/');
                if (strcasecmp((string) $host, $name) === 0
                    || ($port !== null && in_array((int) $port, array_map('intval', $candidate['ports'] ?? []), true))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * What's set for the app: "auto" (follow its settings), "none", or a
     * database chosen by hand, kept by a name that survives it being
     * recreated.
     */
    public static function setting(Machine $machine, string $app): string
    {
        return (string) ($machine->app_settings[$app]['database'] ?? 'auto');
    }

    /**
     * The database a deploy of the app snapshots, from its settings or as
     * chosen.
     *
     * @param  array<string, mixed>  $env  the deploy's settings
     * @return array<string, mixed>|null
     */
    public static function for(Machine $machine, string $app, array $env): ?array
    {
        $setting = self::setting($machine, $app);

        return match ($setting) {
            'none' => null,
            'auto' => self::detect($machine, $env),
            default => collect(self::candidates($machine))->first(fn (array $s) => ServiceLabels::identity($s) === $setting),
        };
    }
}
