<?php

namespace App\Support\Projects;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An app's settings (its environment variables), as the canvas shows and
 * changes them: by name only, values never sent back; the ones a
 * connection writes are marked with the service they come from and left
 * to it. Saving deploys the live version again with the change, the way
 * the update_app_settings tool does.
 *
 * An app whose deploys all failed has settings too, from its last try:
 * a wrong one is often why it failed, and saving tries again with the fix.
 * The canvas and the app's own page both change them here.
 */
class AppSettings
{
    /** Read while the app is built, so they need a new build to change. */
    private const string PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * @return array{names: list<array{name: string, from: string|null, build: bool}>, deployed: bool}
     */
    public function read(ProjectNode $app): array
    {
        return $this->readApp($app->machine, $app->name);
    }

    /**
     * @return array{names: list<array{name: string, from: string|null, build: bool}>, deployed: bool}
     */
    public function readApp(Machine $machine, string $name): array
    {
        $last = self::lastDeploy($machine, $name);
        $env = (array) ($last?->command['job']['env'] ?? []);
        $from = $this->managed($machine, $name);

        $names = array_keys($env);
        sort($names);

        return [
            'names' => array_map(fn (string $name) => [
                'name' => $name,
                'from' => $from[$name] ?? null,
                'build' => preg_match(self::PUBLIC_ENV, $name) === 1,
            ], $names),
            'deployed' => $last !== null,
        ];
    }

    /**
     * Change some settings and deploy the app again with them.
     *
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @return list<string> the names that changed
     */
    public function write(ProjectNode $app, array $set, array $remove, User $user): array
    {
        return $this->writeApp($app->machine, $app->name, $set, $remove, $user);
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @return list<string> the names that changed
     */
    public function writeApp(Machine $machine, string $name, array $set, array $remove, User $user): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['settings' => $message]);
        $managed = $this->managed($machine, $name);

        foreach ([...array_keys($set), ...$remove] as $setting) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $setting)) {
                $fail("{$setting} isn’t a setting name: use letters, digits and underscores, not starting with a digit.");
            }
            if (preg_match(self::PUBLIC_ENV, (string) $setting)) {
                $fail("{$setting} is read while the app is built, so it needs a new build to change.");
            }
            if (isset($managed[$setting])) {
                $fail("{$setting} comes from the connection to {$managed[$setting]}. Disconnect it to change it yourself.");
            }
        }
        if (array_intersect(array_keys($set), $remove) !== []) {
            $fail('A setting can’t be both changed and removed.');
        }

        // Built on the newest deploy, even one still going, so two saves in
        // a row both count.
        $last = self::lastDeploy($machine, $name);
        if ($last === null || ! $last->isRetryable()) {
            $fail("{$name} has no deploy to change yet.");
        }
        $spec = (array) $last->command['job'];
        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            $fail("The code uploaded for {$name}’s live version has been cleared. Upload it again first.");
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            $fail($reason);
        }

        $env = array_map('strval', (array) ($spec['env'] ?? []));
        $next = array_diff_key([...$env, ...$set], array_flip($remove));
        $changed = array_values(array_unique([
            ...array_keys(array_filter($set, fn (string $value, string $name) => ($env[$name] ?? null) !== $value, ARRAY_FILTER_USE_BOTH)),
            ...array_intersect($remove, array_keys($env)),
        ]));
        if ($changed === []) {
            return [];
        }

        $spec['env'] = (object) $next;
        try {
            $this->queue->deploy($machine, $user, $spec);
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
        }

        return $changed;
    }

    /**
     * The deploy settings are read from and built on: the newest that
     * hasn't failed, or when every one has, the newest try.
     */
    public static function lastDeploy(Machine $machine, string $name): ?DaemonJob
    {
        return Placement::latestDeploy($machine, $name) ?? DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $name)
            ->latest()
            ->first();
    }

    /**
     * The names each connection wrote, to the service it connects to, from
     * any project the app is on.
     *
     * @return array<string, string>
     */
    private function managed(Machine $machine, string $name): array
    {
        $apps = ProjectNode::query()->where('machine_id', $machine->id)->where('name', $name)->where('kind', 'app')->pluck('id');
        $from = [];
        foreach (ProjectLink::query()->whereIn('app_node_id', $apps)->where('state', 'applied')->with('resource')->get() as $link) {
            foreach ((array) ($link->env ?? []) as $name) {
                $from[(string) $name] = $link->resource->name;
            }
        }

        return $from;
    }
}
