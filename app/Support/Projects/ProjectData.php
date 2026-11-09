<?php

namespace App\Support\Projects;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\DaemonJob;
use App\Models\ProjectEnvironment;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonFacts;
use App\Support\DaemonJobQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fills a database in a copied environment (Staging) with the data of the
 * one it was copied from (Production): a dump taken over the project's
 * private network, or Docker's bridge on the same machine, and loaded into
 * the copy, replacing what's there. Runs inside the copy's own container,
 * so nothing is installed on the machine. The original's password goes in
 * as a setting by name, never in the command, so it stays out of the log.
 *
 * Only ever writes into a copy: never into an environment nothing was
 * copied into it from, so Production can't be overwritten this way.
 */
class ProjectData
{
    /** What each database's copy runs, inside the copy's container. */
    public const array COPYABLE = ['postgres', 'mysql', 'mariadb', 'mongodb'];

    private const int TIMEOUT = 3600;

    public function __construct(
        private readonly DaemonJobQueue $queue,
    ) {}

    public static function copyable(ProjectNode $node): bool
    {
        return in_array($node->template, self::COPYABLE, true);
    }

    /**
     * Copy every database in the environment again from its source. Returns
     * each database asked, with how it went: copying (and its job), waiting
     * or failed.
     *
     * @return list<ProjectNode>
     */
    public function refresh(ProjectEnvironment $environment, User $user): array
    {
        $asked = [];
        foreach ($environment->nodes()->whereNotNull('origin_id')->with(['machine', 'origin.machine', 'project'])->get() as $node) {
            if (self::copyable($node) && $node->data_state !== 'copying') {
                $this->start($node, $user);
                $asked[] = $node->refresh();
            }
        }
        $environment->forceFill(['refreshed_at' => now()])->save();

        return $asked;
    }

    /**
     * Start copying one database's data from its origin, or say why it
     * has to wait (the copy isn't up yet, the network hasn't joined).
     */
    public function start(ProjectNode $node, User $user): void
    {
        // One copy at a time per database: the tick, a deploy finishing and
        // a click can all ask at once. Whoever holds the row decides.
        DB::transaction(function () use ($node, $user) {
            $locked = ProjectNode::query()->lockForUpdate()->find($node->id);
            if ($locked === null || $locked->data_state === 'copying') {
                return;
            }
            $node->setRawAttributes($locked->getAttributes(), true);
            $this->startLocked($node, $user);
        });
    }

    private function startLocked(ProjectNode $node, User $user): void
    {
        $node->loadMissing(['machine', 'origin.machine', 'project', 'environment']);
        $origin = $node->origin;
        if ($origin === null || ! self::copyable($node) || $origin->template !== $node->template || $node->environment?->source_id === null) {
            $node->forceFill(['data_state' => null, 'data_error' => null])->save();

            return;
        }

        $wait = fn (string $why) => $node->forceFill(['data_state' => 'waiting', 'data_error' => $why])->save();

        $source = Deploys::lastDeploy($origin->machine, $origin->name);
        $target = Deploys::lastDeploy($node->machine, $node->name);
        if ($source === null) {
            $node->forceFill(['data_state' => 'failed', 'data_error' => "{$origin->name} has no deploy to copy from."])->save();

            return;
        }
        if ($target === null) {
            $wait("Waiting for {$node->name} to finish deploying.");

            return;
        }
        $container = collect(Placement::containers($node->machine, $node->name))
            ->first(fn (array $service) => str_starts_with((string) ($service['key'] ?? ''), 'docker:'));
        if ($container === null) {
            $wait("Waiting for {$node->machine->name} to report {$node->name}’s container.");

            return;
        }
        $host = $this->reach($node, $origin);
        if ($host === null) {
            $wait('Waiting for both machines to join the project’s private network.');

            return;
        }
        if (($reason = $this->queue->unavailable($node->machine)) !== null) {
            $wait($reason);

            return;
        }
        if (! DaemonJobQueue::canProjectData($node->machine)) {
            $node->forceFill(['data_state' => 'failed', 'data_error' => "{$node->machine->name}’s daemon is too old to copy data. It updates itself, or run “sudo serverosd update” on the machine."])->save();

            return;
        }

        $sourceSpec = (array) $source->command['job'];
        $sourceEnv = array_map('strval', (array) ($sourceSpec['env'] ?? []));
        $port = (string) ($sourceSpec['port'] ?? '');

        try {
            $job = $this->queue->queue($node->machine, $user, 'service_exec', [
                'type' => 'service_exec',
                'service' => (string) $container['key'],
                'command' => self::command((string) $node->template),
                'timeout_secs' => self::TIMEOUT,
                'env' => (object) self::sourceEnv((string) $node->template, $sourceEnv, $host, $port),
            ], (string) $container['key'], self::TIMEOUT + 60);
        } catch (RuntimeException $e) {
            $node->forceFill(['data_state' => 'failed', 'data_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return;
        }

        $node->forceFill(['data_state' => 'copying', 'data_error' => null, 'data_job_id' => $job->id])->save();
    }

    /** A database in a copy finished deploying: fill it, if it's waiting to be. */
    public function deployFinished(DaemonJob $job): void
    {
        if ($job->kind !== 'deploy' || $job->state !== 'succeeded' || ! is_string($job->service) || $job->user === null) {
            return;
        }
        $nodes = ProjectNode::query()->where('machine_id', $job->machine_id)->where('name', $job->service)->where('data_state', 'waiting')->get();
        foreach ($nodes as $node) {
            $this->start($node, $job->user);
        }
    }

    /** A copy finished: copied, or why it couldn't. */
    public function jobFinished(DaemonJob $job): void
    {
        if ($job->kind !== 'service_exec') {
            return;
        }
        $node = ProjectNode::query()->where('data_job_id', $job->id)->first();
        if ($node === null) {
            return;
        }

        $code = $job->result['exit_code'] ?? null;
        if ($job->state === 'succeeded' && (int) $code === 0) {
            $node->forceFill(['data_state' => 'copied', 'data_error' => null, 'data_at' => now()])->save();

            return;
        }

        $node->forceFill([
            'data_state' => 'failed',
            'data_error' => mb_substr((string) ($job->error['message'] ?? ($code !== null
                ? "The copy stopped (exit code {$code}). Its log on {$node->machine->name}’s page says why."
                : 'The copy didn’t finish.')), 0, 500),
        ])->save();
    }

    /**
     * Every minute: start copies that were waiting, and copy again into
     * environments due a refresh.
     */
    public function tick(CarbonImmutable $now): void
    {
        // A copy whose job finished without the gateway saying so (stopped
        // waiting on, lost), or that never reported back: settle it.
        foreach (ProjectNode::query()->where('data_state', 'copying')->get() as $node) {
            $job = $node->data_job_id !== null ? DaemonJob::query()->find($node->data_job_id) : null;
            if ($job === null) {
                $node->forceFill(['data_state' => 'failed', 'data_error' => 'The copy’s job is gone. Copy the data again.'])->save();
            } elseif (! in_array($job->state, ['queued', 'running'], true)) {
                $this->jobFinished($job);
            } elseif ($job->created_at?->lt($now->subSeconds(self::TIMEOUT + 15 * 60))) {
                $node->forceFill(['data_state' => 'failed', 'data_error' => "{$node->name}’s machine didn’t report the copy finishing. Copy the data again."])->save();
            }
        }

        foreach (ProjectNode::query()->where('data_state', 'waiting')->whereHas('environment', fn ($q) => $q->whereNull('removing_at'))->with(['project.workspace.owner'])->get() as $node) {
            $owner = $node->project->workspace->owner;
            if ($owner !== null) {
                $this->start($node, $owner);
            }
        }

        foreach (ProjectEnvironment::query()->whereNotNull('refresh')->whereNotNull('source_id')->whereNull('removing_at')->with('project.workspace.owner')->get() as $environment) {
            $owner = $environment->project->workspace->owner;
            if ($owner !== null && $environment->refreshDue($now)) {
                $this->refresh($environment, $owner);
            }
        }
    }

    /** Where the copy's machine reaches the original. */
    private function reach(ProjectNode $node, ProjectNode $origin): ?string
    {
        if ($node->machine_id === $origin->machine_id && (Connections::TEMPLATES[$origin->template]['bridge'] ?? false)) {
            return DaemonFacts::dockerBridge($origin->machine->facts ?? []);
        }
        $project = $node->project;
        $provider = $project->members()->where('machine_id', $origin->machine_id)->first();
        if ($provider?->state !== 'up' || ! ProjectNetwork::isUp($project, $node->machine)) {
            return null;
        }

        return $provider->setRelation('project', $project)->address();
    }

    /**
     * The original's address and login, handed to the copy by name.
     *
     * @param  array<string, string>  $env  the original's deploy settings
     * @return array<string, string>
     */
    public static function sourceEnv(string $template, array $env, string $host, string $port): array
    {
        $common = ['SOURCE_HOST' => $host, 'SOURCE_PORT' => $port];

        return match ($template) {
            'postgres' => $common + [
                'SOURCE_USER' => $env['POSTGRES_USER'] ?? 'postgres',
                'SOURCE_PASSWORD' => $env['POSTGRES_PASSWORD'] ?? '',
                'SOURCE_DB' => $env['POSTGRES_DB'] ?? 'postgres',
            ],
            'mysql' => $common + [
                'SOURCE_PASSWORD' => $env['MYSQL_ROOT_PASSWORD'] ?? '',
                'SOURCE_DB' => $env['MYSQL_DATABASE'] ?? '',
            ],
            'mariadb' => $common + [
                'SOURCE_PASSWORD' => $env['MARIADB_ROOT_PASSWORD'] ?? '',
                'SOURCE_DB' => $env['MARIADB_DATABASE'] ?? '',
            ],
            'mongodb' => [
                'SOURCE_URI' => 'mongodb://'.rawurlencode($env['MONGO_USER'] ?? 'admin').':'.rawurlencode($env['MONGO_PASSWORD'] ?? '')."@{$host}:{$port}/?authSource=admin",
            ],
            default => throw new RuntimeException("{$template} can’t be copied."),
        };
    }

    /**
     * The command run inside the copy's container. Its own login comes
     * from the container's environment; the original's from SOURCE_*.
     */
    public static function command(string $template): string
    {
        $dump = '/tmp/serveros-copy';

        return match ($template) {
            'postgres' => implode("\n", [
                'set -e',
                "trap 'rm -f {$dump}.sql' EXIT",
                'PGPASSWORD="$SOURCE_PASSWORD" pg_dump -h "$SOURCE_HOST" -p "$SOURCE_PORT" -U "$SOURCE_USER" -d "$SOURCE_DB" --clean --if-exists --no-owner --no-privileges -f '.$dump.'.sql',
                'psql -v ON_ERROR_STOP=1 -q -o /dev/null -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f '.$dump.'.sql',
                'echo "Copied $SOURCE_DB."',
            ]),
            'mysql' => implode("\n", [
                'set -e',
                "trap 'rm -f {$dump}.sql' EXIT",
                'MYSQL_PWD="$SOURCE_PASSWORD" mysqldump -h "$SOURCE_HOST" -P "$SOURCE_PORT" -u root --single-transaction --no-tablespaces --routines --triggers "$SOURCE_DB" > '.$dump.'.sql',
                'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root "$MYSQL_DATABASE" < '.$dump.'.sql',
                'echo "Copied $SOURCE_DB."',
            ]),
            'mariadb' => implode("\n", [
                'set -e',
                "trap 'rm -f {$dump}.sql' EXIT",
                // Over the private network or the bridge: no TLS to verify.
                'MYSQL_PWD="$SOURCE_PASSWORD" mariadb-dump --skip-ssl -h "$SOURCE_HOST" -P "$SOURCE_PORT" -u root --single-transaction --routines --triggers "$SOURCE_DB" > '.$dump.'.sql',
                'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --skip-ssl -u root "$MARIADB_DATABASE" < '.$dump.'.sql',
                'echo "Copied $SOURCE_DB."',
            ]),
            'mongodb' => implode("\n", [
                'set -e',
                'umask 077',
                "trap 'rm -f {$dump}.archive {$dump}-source.yaml {$dump}-target.yaml' EXIT",
                // The logins go in files (printf is the shell's own, so
                // never in ps), not on mongodump's command line.
                'printf "uri: %s\\n" "$SOURCE_URI" > '.$dump.'-source.yaml',
                'printf "uri: mongodb://%s:%s@127.0.0.1:27017/?authSource=admin\\n" "$MONGO_INITDB_ROOT_USERNAME" "$MONGO_INITDB_ROOT_PASSWORD" > '.$dump.'-target.yaml',
                'mongodump --config='.$dump.'-source.yaml --archive='.$dump.'.archive --quiet',
                // The copy keeps its own users: admin holds the logins its apps use.
                'mongorestore --config='.$dump.'-target.yaml --archive='.$dump.'.archive --drop --quiet --nsExclude="admin.*" --nsExclude="config.*" --nsExclude="local.*"',
                'echo "Copied."',
            ]),
            default => throw new RuntimeException("{$template} can’t be copied."),
        };
    }
}
