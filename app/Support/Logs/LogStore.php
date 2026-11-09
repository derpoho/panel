<?php

namespace App\Support\Logs;

use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Where a workspace's log lines live: our database, or (Business) a
 * Postgres the workspace owns. Every read and write of log_entries and
 * log_counts asks here for the connection, so a workspace's lines go to
 * one place and are read from that place only. Lines are never written to
 * ours for a workspace whose own store is enabled, even when it's down.
 */
final class LogStore
{
    /** Oldest Postgres we accept: generated columns and good LIKE plans. */
    public const MIN_POSTGRES = 13;

    /** @var array<int, string> connection by workspace id, for this request */
    private static array $connections = [];

    /** @var array<int, int> workspace id by machine id, for this request */
    private static array $workspaces = [];

    /** Our own connection: where lines live unless a workspace has its own. */
    public static function ours(): string
    {
        return (string) config('database.default');
    }

    /** The connection a workspace's lines are read from and written to. */
    public static function connection(Workspace|int $workspace): string
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return self::$connections[$id] ??= self::resolve($id);
    }

    public static function connectionForMachine(Machine|int $machine): string
    {
        if ($machine instanceof Machine) {
            return self::connection($machine->workspace_id);
        }
        $workspaceId = self::$workspaces[$machine] ??= (int) Machine::query()->whereKey($machine)->value('workspace_id');

        return self::connection($workspaceId);
    }

    /** Whether the workspace keeps its lines in its own database. */
    public static function isExternal(Workspace|int $workspace): bool
    {
        return self::connection($workspace) !== self::ours();
    }

    /** @return Builder<LogEntry> */
    public static function entries(Workspace|int $workspace): Builder
    {
        return LogEntry::on(self::connection($workspace));
    }

    /** The workspace's per-minute counts, on the right connection. */
    public static function counts(Workspace|int $workspace): QueryBuilder
    {
        return DB::connection(self::connection($workspace))->table('log_counts');
    }

    /** Forget what this request resolved: after a store is saved, and in tests. */
    public static function flush(): void
    {
        // Every store connection this process opened, however it was
        // opened (resolved, tested or installed), so changed details apply.
        foreach (array_keys((array) config('database.connections')) as $name) {
            if (str_starts_with((string) $name, 'logs_ws_')) {
                DB::purge($name);
            }
        }
        self::$connections = [];
        self::$workspaces = [];
    }

    private static function resolve(int $workspaceId): string
    {
        $store = WorkspaceLogStore::query()->with('workspace')->where('workspace_id', $workspaceId)->where('enabled', true)->first();
        // Business only: once a workspace leaves it, its lines come back to
        // us (daily limit and all) until it's on Business again, when its
        // store picks up where it left off.
        if ($store === null || ! $store->workspace->hasOwnLogStorage()) {
            return self::ours();
        }

        return self::register($store);
    }

    /** Register the store's connection and return its name. */
    public static function register(WorkspaceLogStore $store): string
    {
        $name = 'logs_ws_'.$store->workspace_id;
        // Close any connection open under this name first: its details may
        // have changed since.
        DB::purge($name);
        config()->set("database.connections.{$name}", self::config($store));

        return $name;
    }

    /** @return array<string, mixed> */
    private static function config(WorkspaceLogStore $store): array
    {
        if ($store->driver === 'sqlite') {
            if (! app()->runningUnitTests()) {
                throw new RuntimeException('Log stores are Postgres.');
            }

            return ['driver' => 'sqlite', 'database' => $store->database, 'prefix' => '', 'foreign_key_constraints' => false];
        }

        return [
            'driver' => 'pgsql',
            'host' => $store->host,
            'port' => $store->port,
            'database' => $store->database,
            'username' => $store->username,
            'password' => $store->password,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            // Their database, over the internet: always encrypted, and a
            // slow one mustn't hold up ingest for long.
            'sslmode' => 'require',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
    }

    /**
     * Connect to a store and check it's fit to use: reachable, Postgres
     * 13 or newer, and allowed to create our tables. Null when it's fine,
     * or why not, in words to show.
     */
    public static function test(WorkspaceLogStore $store): ?string
    {
        $name = self::register($store);
        try {
            $db = DB::connection($name);
            if ($store->driver === 'pgsql') {
                $version = (int) $db->selectOne('show server_version_num')?->server_version_num;
                if ($version < self::MIN_POSTGRES * 10000) {
                    return 'It’s Postgres '.intdiv($version, 10000).'. ServerOS needs '.self::MIN_POSTGRES.' or newer.';
                }
                $canCreate = (bool) $db->selectOne("select has_schema_privilege(current_user, 'public', 'CREATE') as ok")?->ok;
                if (! $canCreate) {
                    return 'The user can’t create tables in the public schema. Grant it CREATE there.';
                }
            } else {
                $db->select('select 1');
            }

            return null;
        } catch (Throwable $e) {
            Log::info('Log store test failed', ['workspace' => $store->workspace_id, 'error' => self::reason($e)]);

            return self::explain($e, $store);
        } finally {
            DB::purge($name);
        }
    }

    /** Create our two tables in the store, if they aren't there yet. */
    public static function install(WorkspaceLogStore $store): void
    {
        $schema = DB::connection(self::register($store))->getSchemaBuilder();

        if (! $schema->hasTable('log_entries')) {
            $schema->create('log_entries', function (Blueprint $table) {
                $table->id();
                // Machine ids are ours; there's no machines table here.
                $table->unsignedBigInteger('machine_id');
                $table->string('service_key', 300);
                $table->string('service', 200);
                $table->unsignedBigInteger('logged_at');
                $table->string('level', 5);
                $table->string('stream', 7);
                $table->text('message');

                $table->index(['machine_id', 'id']);
                $table->index(['machine_id', 'service', 'id']);
                $table->index('logged_at');
            });
        }
        if (! $schema->hasTable('log_counts')) {
            $schema->create('log_counts', function (Blueprint $table) {
                $table->unsignedBigInteger('machine_id');
                $table->bigInteger('minute');
                $table->string('service', 200);
                $table->string('level', 10);
                $table->unsignedInteger('n');

                $table->unique(['machine_id', 'minute', 'service', 'level']);
            });
        }
    }

    /** Note that the store answered, or didn't, for the settings and Logs page. */
    public static function markOk(Workspace|int $workspace): void
    {
        WorkspaceLogStore::query()->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->update(['status' => 'ok', 'last_error' => null, 'last_ok_at' => now()]);
    }

    public static function markUnreachable(Workspace|int $workspace, Throwable $e): void
    {
        WorkspaceLogStore::query()->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->update(['status' => 'unreachable', 'last_error' => self::reason($e)]);
    }

    /**
     * Why a connection failed, in words someone setting it up can act on.
     * The driver's own message is logged; this is what they see.
     */
    public static function explain(Throwable $e, WorkspaceLogStore $store): string
    {
        $raw = strtolower($e->getMessage());
        $where = "{$store->host}:{$store->port}";

        return match (true) {
            str_contains($raw, 'timeout expired') || str_contains($raw, 'timed out') => "Timed out reaching {$where}. Check the host and port, and that your firewall lets in ServerOS’s address.",
            str_contains($raw, 'connection refused') => "{$where} refused the connection. Check Postgres is running and listening on that port.",
            str_contains($raw, 'could not translate host name') || str_contains($raw, 'name or service not known') || str_contains($raw, 'nodename nor servname') => "Couldn’t find a server called {$store->host}. Check the host name.",
            str_contains($raw, 'password authentication failed') => 'The user or password isn’t right.',
            str_contains($raw, 'role') && str_contains($raw, 'does not exist') => "There’s no user called {$store->username} on that server.",
            str_contains($raw, 'database') && str_contains($raw, 'does not exist') => "There’s no database called {$store->database} on that server.",
            str_contains($raw, 'ssl') => 'The server doesn’t accept encrypted (SSL) connections. ServerOS only connects over SSL, so turn it on in Postgres.',
            str_contains($raw, 'pg_hba.conf') => 'Postgres turned ServerOS away. Add ServerOS’s address to pg_hba.conf for this database and user.',
            str_contains($raw, 'permission denied') => 'The user doesn’t have permission. It needs CREATE on the public schema.',
            default => 'Couldn’t connect: '.self::reason($e),
        };
    }

    /** An error in a line people can read, without the password in a DSN. */
    public static function reason(Throwable $e): string
    {
        $message = preg_replace('/password=\S+/i', 'password=[redacted]', $e->getMessage()) ?? '';
        // PDO errors repeat the whole SQL; the first line says what's wrong.
        $first = strtok($message, "\n");

        return mb_substr(trim($first === false ? $message : $first), 0, 300);
    }
}
