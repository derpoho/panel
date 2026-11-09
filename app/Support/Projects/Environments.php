<?php

namespace App\Support\Projects;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Environments: a copy of one (Staging from Production) on the machines
 * you choose. Its databases, caches and storage are new deploys of the same
 * templates with passwords of their own, its apps the same code under new
 * names with no domain, and its connections the same as the original's,
 * applied once each part is up. Then, if asked, the databases are filled
 * with the original's data.
 *
 * Promoting goes the other way: each app in the target environment is
 * deployed again at the code its copy runs, keeping its own settings.
 */
class Environments
{
    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly ServiceTemplateCatalog $catalog,
        private readonly Connections $connections,
        private readonly ProjectNetwork $network,
    ) {}

    /**
     * @param  array<int, Machine>  $machines  each of the source's machines (by id) to the one its copy goes on
     * @param  (callable(array<string, mixed>, ProjectNode): array<string, mixed>)|null  $adjustApp  changes a copied app's deploy before it's sent (a preview's code and domain)
     * @param  array<string, mixed>  $attributes  more about the environment, like the pull request it's for
     * @return array{0: ProjectEnvironment, 1: list<string>} the environment, and what couldn't be copied
     */
    public function create(Project $project, ProjectEnvironment $source, string $name, array $machines, bool $copyData, User $user, ?callable $adjustApp = null, array $attributes = []): array
    {
        $environment = $project->environments()->create([
            'name' => $name,
            'position' => (int) $project->environments()->max('position') + 1,
            'source_id' => $source->id,
        ]);
        if ($attributes !== []) {
            $environment->forceFill($attributes)->save();
        }
        $suffix = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));

        $copies = [];
        $skipped = [];
        $dropped = [];
        // Resources first, so they're on their way before the apps that use them.
        $nodes = $source->nodes()->with('machine')->get()->sortBy(fn (ProjectNode $node) => $node->isApp() ? 1 : 0);
        foreach ($nodes as $node) {
            $machine = $machines[$node->machine_id] ?? $node->machine;
            try {
                if ($node->isApp()) {
                    [$copies[$node->id], $left] = $this->copyApp($environment, $node, $machine, $suffix, $user, $adjustApp);
                    if ($left !== []) {
                        $dropped[$copies[$node->id]->name] = $left;
                    }
                } else {
                    $copies[$node->id] = $this->copyResource($environment, $node, $machine, $suffix, $copyData, $user);
                }
            } catch (RuntimeException $e) {
                $skipped[] = "{$node->name}: {$e->getMessage()}";
            }
        }

        // The same connections between the copies.
        foreach (ProjectLink::query()->whereIn('app_node_id', $nodes->pluck('id'))->get() as $link) {
            if (isset($copies[$link->app_node_id], $copies[$link->resource_node_id])) {
                $project->links()->firstOrCreate([
                    'app_node_id' => $copies[$link->app_node_id]->id,
                    'resource_node_id' => $copies[$link->resource_node_id]->id,
                ]);
            }
        }

        $this->network->sync($project, $user);

        foreach ($dropped as $app => $names) {
            $skipped[] = "{$app} was left without ".implode(', ', $names).", which reach {$source->name}’s services. Its own connections fill in the usual ones; set any others in its Settings.";
        }

        return [$environment, $skipped];
    }

    /**
     * Take an environment out of the project. Without $removeServices its
     * apps and services keep running where they are. With it, they're
     * removed from their machines with their data; whatever can't go yet
     * (a machine offline, a deploy still going) is retried every minute,
     * with the environment hidden and its name and pull request freed.
     */
    public function remove(ProjectEnvironment $environment, bool $removeServices, User $user): void
    {
        $project = $environment->project;
        if (! $removeServices) {
            $environment->delete();
            $this->network->sync($project, $user);

            return;
        }

        $environment->forceFill([
            'removing_at' => now(),
            'name' => mb_substr("{$environment->name} (removing {$environment->id})", 0, 40),
            'pull_request' => null,
            'refresh' => null,
        ])->save();
        $this->continueRemoving($environment, $user);
    }

    /**
     * Take what's left of an environment being removed off its machines,
     * and drop it once nothing of it is left. After a day it's given up on,
     * and what may still be running is named in the activity.
     */
    public function continueRemoving(ProjectEnvironment $environment, User $user): void
    {
        $project = $environment->project;
        foreach ($environment->nodes()->with('machine')->get() as $node) {
            if ($this->removeFromMachine($node, $user)) {
                $node->delete();
            }
        }

        $left = $environment->nodes()->with('machine')->get();
        if ($left->isNotEmpty() && $environment->removing_at?->gt(now()->subDay())) {
            return;
        }
        if ($left->isNotEmpty()) {
            Activity::system($project->workspace, 'project.environment_leftovers', $project->name, meta: [
                'names' => $left->map(fn (ProjectNode $node) => "{$node->name} on {$node->machine->name}")->all(),
            ]);
        }
        $environment->delete();
        $this->network->sync($project, $user);
    }

    /**
     * What promoting $from into $to would do: each app in $to that has a
     * copy in $from, the code each runs now and the code it would get, and
     * the settings only the copy has (which don't come across).
     *
     * @return list<array{app: string, machine: string, from: string|null, to: string|null, repo: bool, missing: list<string>, ready: bool, reason: string|null}>
     */
    public function plan(ProjectEnvironment $from, ProjectEnvironment $to): array
    {
        return array_values($this->pairs($from, $to)->map(function (array $pair) {
            [$copy, $target] = $pair;
            $source = Deploys::lastDeploy($copy->machine, $copy->name);
            $live = Deploys::lastDeploy($target->machine, $target->name);
            $sourceSpec = (array) ($source?->command['job'] ?? []);
            $liveSpec = (array) ($live?->command['job'] ?? []);

            $managed = fn (ProjectNode $app) => ProjectLink::query()->where('app_node_id', $app->id)->pluck('env')->flatten()->filter()->all();
            $missing = array_values(array_map('strval', array_diff(
                array_keys((array) ($sourceSpec['env'] ?? [])),
                array_keys((array) ($liveSpec['env'] ?? [])),
                $managed($copy),
            )));

            $reason = match (true) {
                $source === null => "{$copy->name} hasn’t deployed yet.",
                $live === null => "{$target->name} hasn’t deployed yet.",
                blank($sourceSpec['repo'] ?? null) => "{$copy->name} wasn’t deployed from code.",
                Deploys::fromUpload((string) $sourceSpec['repo']) => "{$copy->name} runs uploaded code, which can’t be promoted.",
                default => null,
            };

            return [
                'app' => $target->name,
                'machine' => $target->machine->name,
                'from' => isset($liveSpec['commit']) ? substr((string) $liveSpec['commit'], 0, 7) : null,
                'to' => isset($sourceSpec['commit']) ? substr((string) $sourceSpec['commit'], 0, 7) : null,
                'repo' => ($sourceSpec['repo'] ?? null) !== ($liveSpec['repo'] ?? null),
                'missing' => $missing,
                'ready' => $reason === null && ($sourceSpec['commit'] ?? null) !== ($liveSpec['commit'] ?? null),
                'reason' => $reason ?? (($sourceSpec['commit'] ?? null) === ($liveSpec['commit'] ?? null) ? 'Already at this code.' : null),
            ];
        })->all());
    }

    /**
     * Deploy each app in $to at the code its copy in $from runs, keeping
     * its own settings, domain and port. All or nothing: if any app can't
     * be deployed (its machine is offline, its copy runs uploaded code or
     * hasn't deployed), none is, and why comes back in `blocked`.
     *
     * @return array{deployed: array<string, DaemonJob>, blocked: array<string, string>}
     */
    public function promote(ProjectEnvironment $from, ProjectEnvironment $to, User $user): array
    {
        $ready = [];
        $blocked = [];
        foreach ($this->pairs($from, $to) as [$copy, $target]) {
            $source = Deploys::lastDeploy($copy->machine, $copy->name);
            // Built on the newest deploy, so a settings change still going
            // out isn't undone by the promote.
            $live = Placement::latestDeploy($target->machine, $target->name);
            $sourceSpec = (array) ($source?->command['job'] ?? []);
            $spec = (array) ($live?->command['job'] ?? []);
            $reason = match (true) {
                $source === null => "{$copy->name} hasn’t deployed yet.",
                $live === null => "{$target->name} hasn’t deployed yet.",
                blank($sourceSpec['repo'] ?? null) => "{$copy->name} wasn’t deployed from code.",
                Deploys::fromUpload((string) $sourceSpec['repo']) => "{$copy->name} runs uploaded code, which can’t be promoted.",
                default => null,
            };
            if ($reason === null && ($sourceSpec['commit'] ?? null) === ($spec['commit'] ?? null)) {
                continue;
            }
            $reason ??= $this->queue->unavailable($target->machine, 'deploy');
            if ($reason !== null) {
                $blocked[$target->name] = $reason;

                continue;
            }

            $spec['repo'] = $sourceSpec['repo'];
            $spec['commit'] = $sourceSpec['commit'];
            if (isset($sourceSpec['dockerfile'])) {
                $spec['dockerfile'] = $sourceSpec['dockerfile'];
            }
            $spec['env'] = (object) (array) ($spec['env'] ?? []);
            $ready[$target->name] = [$target->machine, $spec];
        }

        if ($blocked !== []) {
            return ['deployed' => [], 'blocked' => $blocked];
        }

        $deployed = [];
        foreach ($ready as $name => [$machine, $spec]) {
            try {
                $deployed[$name] = $this->queue->deploy($machine, $user, $spec);
            } catch (RuntimeException $e) {
                $blocked[$name] = $e->getMessage();
            }
        }

        return ['deployed' => $deployed, 'blocked' => $blocked];
    }

    /** Whether one of the two was copied from the other, so their apps pair up. */
    public static function related(ProjectEnvironment $a, ProjectEnvironment $b): bool
    {
        return $a->id !== $b->id && ($a->source_id === $b->id || $b->source_id === $a->id);
    }

    /**
     * Apps in $from paired with the app in $to they're a copy of (or that
     * is a copy of them).
     *
     * @return Collection<int, array{0: ProjectNode, 1: ProjectNode}>
     */
    private function pairs(ProjectEnvironment $from, ProjectEnvironment $to): Collection
    {
        $targets = $to->nodes()->where('kind', 'app')->with('machine')->get();

        return $from->nodes()->where('kind', 'app')->with('machine')->get()
            ->map(function (ProjectNode $copy) use ($targets) {
                $target = $targets->first(fn (ProjectNode $t) => $t->id === $copy->origin_id || $t->origin_id === $copy->id);

                return $target === null ? null : [$copy, $target];
            })
            ->filter()
            ->values();
    }

    private function copyResource(ProjectEnvironment $environment, ProjectNode $node, Machine $machine, string $suffix, bool $copyData, User $user): ProjectNode
    {
        $slug = $node->template;
        if ($slug === null) {
            $deploy = Deploys::lastDeploy($node->machine, $node->name);
            $slug = $deploy !== null ? $this->connections->templateOf($deploy) : null;
        }
        $template = $slug !== null ? $this->catalog->find($slug) : null;
        if ($template === null) {
            throw new RuntimeException('it wasn’t added from a template ServerOS can copy.');
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new RuntimeException($reason);
        }

        $name = Placement::freeName($machine, "{$node->name}-{$suffix}");
        $deployment = new TemplateDeployment($template, $machine, [
            'service' => $name,
            'port' => Placement::freePort($machine, $template->port),
            'env' => [],
        ]);
        $this->queue->deploy($machine, $user, $deployment->job());

        $copyable = $copyData && in_array($template->slug, ProjectData::COPYABLE, true);

        return self::node($environment, [
            'machine_id' => $machine->id,
            'name' => $name,
            'kind' => $node->kind,
            'template' => $template->slug,
            'origin_id' => $node->id,
            'data_state' => $copyable ? 'waiting' : null,
            'data_error' => $copyable ? 'Waiting for it to finish deploying.' : null,
        ]);
    }

    /**
     * @param  (callable(array<string, mixed>, ProjectNode): array<string, mixed>)|null  $adjust
     * @return array{0: ProjectNode, 1: list<string>} the copy, and the settings left out of it
     */
    private function copyApp(ProjectEnvironment $environment, ProjectNode $node, Machine $machine, string $suffix, User $user, ?callable $adjust = null): array
    {
        $last = Deploys::lastDeploy($node->machine, $node->name);
        if ($last === null || ! $last->isRetryable()) {
            throw new RuntimeException('it has no deploy to copy.');
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new RuntimeException($reason);
        }

        $spec = (array) $last->command['job'];
        $name = Placement::freeName($machine, "{$node->name}-{$suffix}");
        // The original's connection settings point at the original's
        // services: the copy gets its own once its connections apply. So do
        // ones set by hand that look like a connection or hold one of the
        // original environment's service passwords: they're left out, so a
        // copy never writes to the original's database.
        $env = array_map('strval', (array) ($spec['env'] ?? []));
        $dropped = array_map('strval', array_keys(array_filter($env, fn (string $value, string $key) => $this->pointsAtOriginal($node, $key, $value), ARRAY_FILTER_USE_BOTH)));
        $spec['service'] = $name;
        $spec['domains'] = [];
        $spec['env'] = (object) array_diff_key($env, array_flip($dropped));
        if ($adjust !== null) {
            $spec = $adjust($spec, $node);
        }
        $this->queue->deploy($machine, $user, $spec);

        $copy = self::node($environment, [
            'machine_id' => $machine->id,
            'name' => $name,
            'kind' => 'app',
            'origin_id' => $node->id,
        ]);

        return [$copy, $dropped];
    }

    /**
     * Whether an app setting reaches the original environment's services:
     * written by one of its connections, named like a connection, or
     * holding one of those services' own passwords or keys.
     */
    private function pointsAtOriginal(ProjectNode $app, string $name, string $value): bool
    {
        $managed = ProjectLink::query()->where('app_node_id', $app->id)->pluck('env')->flatten()->filter()->all();
        if (in_array($name, $managed, true) || preg_match(self::CONNECTION, $name) === 1) {
            return true;
        }
        foreach ($this->originalSecrets($app) as $secret) {
            if (str_contains($value, $secret)) {
                return true;
            }
        }

        return false;
    }

    /** Settings named like a way into a database, cache, storage or websockets. */
    public const string CONNECTION = '/^(DATABASE_URL|DB_[A-Z0-9_]+|(POSTGRES|PG|MYSQL|MARIADB|MONGO|MONGODB)_?[A-Z0-9_]*|REDIS_[A-Z0-9_]+|VALKEY_[A-Z0-9_]+|CACHE_URL|QUEUE_URL|AWS_[A-Z0-9_]+|S3_[A-Z0-9_]+|PUSHER_[A-Z0-9_]+)$/';

    /** @var array<int, list<string>> */
    private array $secretsByEnvironment = [];

    /**
     * The passwords and keys of the services in the app's environment, from
     * their deploys: long enough to be worth matching.
     *
     * @return list<string>
     */
    private function originalSecrets(ProjectNode $app): array
    {
        return $this->secretsByEnvironment[(int) $app->environment_id] ??= array_values(ProjectNode::query()
            ->where('environment_id', $app->environment_id)
            ->where('kind', '!=', 'app')
            ->with('machine')
            ->get()
            ->flatMap(fn (ProjectNode $resource) => collect((array) (Deploys::lastDeploy($resource->machine, $resource->name)?->command['job']['env'] ?? []))
                ->filter(fn ($value, $key) => preg_match('/PASSWORD|SECRET|KEY|TOKEN/', (string) $key) === 1 && strlen((string) $value) >= 6)
                ->map(fn ($value) => (string) $value)
                ->values())
            ->unique()
            ->values()
            ->all());
    }

    /** @param  array<string, mixed>  $attributes */
    private static function node(ProjectEnvironment $environment, array $attributes): ProjectNode
    {
        $node = new ProjectNode;
        $node->forceFill([...$attributes, 'project_id' => $environment->project_id, 'environment_id' => $environment->id])->save();

        return $node;
    }

    /**
     * Ask the node's machine to remove its containers and their data.
     * True once there's nothing of it left to remove: no container in the
     * machine's last scan and no deploy of it still going.
     */
    private function removeFromMachine(ProjectNode $node, User $user): bool
    {
        $pending = DaemonJob::query()->where('machine_id', $node->machine_id)->where('kind', 'deploy')
            ->where('service', $node->name)->whereIn('state', ['queued', 'running'])->exists();
        $containers = array_values(array_filter(
            Placement::containers($node->machine, $node->name),
            fn (array $service) => str_starts_with((string) ($service['key'] ?? ''), 'docker:'),
        ));
        if ($containers === [] && ! $pending) {
            return true;
        }
        // Asked recently: give the machine time to do it and scan again.
        if ($containers === [] || $this->queue->unavailable($node->machine) !== null || $node->removing_at?->gt(now()->subMinutes(10))) {
            return false;
        }

        foreach ($containers as $service) {
            $key = (string) $service['key'];
            try {
                $this->queue->queue($node->machine, $user, 'service_remove', [
                    'type' => 'service_remove', 'service' => $key, 'delete_data' => true, 'delete_adopted_volumes' => false,
                ], $key);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
        $node->forceFill(['removing_at' => now()])->save();

        return false;
    }
}
