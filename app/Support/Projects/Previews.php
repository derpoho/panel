<?php

namespace App\Support\Projects;

use App\Enums\WorkspacePermission;
use App\Mcp\Tools\Deploy\Deploys;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Workflows\ReleaseSpec;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Pull request previews. When a pull request opens on the project's
 * repository, its first environment is copied to the machine chosen for
 * previews, with the apps from that repository at the pull request's code;
 * each new commit deploys them again, and closing it removes the
 * environment and everything in it.
 *
 * Only pull requests from a branch of the repository itself get one: code
 * from a fork could be anyone's, and it would run on your machine.
 */
class Previews
{
    public const int MAX = 10;

    public const int DEFAULT_LIMIT = 3;

    public function __construct(
        private readonly Environments $environments,
        private readonly DaemonJobQueue $queue,
    ) {}

    /**
     * The project's preview settings, with defaults filled in.
     *
     * @return array{enabled: bool, repo: string, machine_id: int|null, copy_data: bool, domain: string|null, limit: int, user_id: int|null}
     */
    public static function settings(Project $project): array
    {
        $previews = (array) ($project->previews ?? []);

        return [
            'enabled' => (bool) ($previews['enabled'] ?? false),
            'repo' => (string) ($previews['repo'] ?? ''),
            'machine_id' => isset($previews['machine_id']) ? (int) $previews['machine_id'] : null,
            'copy_data' => (bool) ($previews['copy_data'] ?? false),
            'domain' => filled($previews['domain'] ?? null) ? (string) $previews['domain'] : null,
            'limit' => max(1, min(self::MAX, (int) ($previews['limit'] ?? self::DEFAULT_LIMIT))),
            'user_id' => isset($previews['user_id']) ? (int) $previews['user_id'] : null,
        ];
    }

    /**
     * owner/name of the GitHub repository a deploy came from, lowercased.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function repoOf(array $spec): ?string
    {
        $parsed = ReleaseSpec::github((string) ($spec['repo'] ?? ''));

        return $parsed === null ? null : strtolower($parsed[0].'/'.$parsed[1]);
    }

    /**
     * The repositories the project's first environment's apps come from.
     *
     * @return list<string>
     */
    public static function repos(Project $project): array
    {
        $repos = [];
        foreach ($project->production()->nodes()->where('kind', 'app')->with('machine')->get() as $app) {
            $spec = (array) (Deploys::lastDeploy($app->machine, $app->name)?->command['job'] ?? []);
            if (($repo = self::repoOf($spec)) !== null) {
                $repos[$repo] = $repo;
            }
        }

        return array_values($repos);
    }

    /** A pull request opened (or reopened): make its environment. */
    public function opened(Project $project, int $number, string $head): ?ProjectEnvironment
    {
        $settings = self::settings($project);
        $user = $this->actor($project);
        if (! $settings['enabled'] || $user === null) {
            return null;
        }
        if (($existing = $this->find($project, $number)) !== null) {
            $this->updated($project, $number, $head);

            return $existing;
        }

        $open = $project->environments()->whereNotNull('pull_request')->count();
        $machine = $project->workspace->machines()->find($settings['machine_id']);
        if ($open >= $settings['limit'] || $machine === null) {
            Activity::system($project->workspace, 'project.preview_skipped', "#{$number} in {$project->name}", meta: [
                'reason' => $machine === null ? 'The machine for previews is gone.' : "{$open} previews are already open, the most this project allows.",
            ]);

            return null;
        }

        $source = $project->production();
        $name = $project->environments()->where('name', "PR #{$number}")->exists() ? "PR #{$number} preview" : "PR #{$number}";
        $machines = [];
        foreach ($source->nodes()->pluck('machine_id')->unique() as $machineId) {
            $machines[(int) $machineId] = $machine;
        }

        $addresses = [];
        try {
            [$environment] = $this->environments->create(
                $project,
                $source,
                $name,
                $machines,
                $settings['copy_data'],
                $user,
                function (array $spec, ProjectNode $original) use ($settings, $number, $head, &$addresses) {
                    return $this->adjust($spec, $original, $settings, $number, $head, $addresses);
                },
                ['pull_request' => $number, 'head' => $head],
            );
        } catch (UniqueConstraintViolationException) {
            // GitHub sent it twice at once: the other delivery made it.
            return $this->find($project, $number);
        }
        Activity::record($project->workspace, 'project.preview', "#{$number} in {$project->name}", $machine, $user, ['commit' => substr($head, 0, 7)]);

        return $environment;
    }

    /**
     * New commits on the pull request: deploy its apps at the new code. The
     * preview only records the new commit once every app took it, so one
     * that couldn't (its machine was offline) gets it on the next push.
     */
    public function updated(Project $project, int $number, string $head): void
    {
        $environment = $this->find($project, $number);
        $user = $this->actor($project);
        $repo = strtolower(self::settings($project)['repo']);
        if ($environment === null || $user === null || $environment->head === $head) {
            return;
        }

        $all = true;
        foreach ($environment->nodes()->where('kind', 'app')->with('machine')->get() as $app) {
            // The newest deploy, even the first one still going.
            $last = Placement::latestDeploy($app->machine, $app->name);
            $spec = (array) ($last?->command['job'] ?? []);
            if ($last === null || self::repoOf($spec) !== $repo) {
                continue;
            }
            if ($this->queue->unavailable($app->machine, 'deploy') !== null) {
                $all = false;

                continue;
            }
            $spec['commit'] = $head;
            $spec['env'] = (object) (array) ($spec['env'] ?? []);
            try {
                $this->queue->deploy($app->machine, $user, $spec);
            } catch (RuntimeException $e) {
                report($e);
                $all = false;
            }
        }
        if (! $all) {
            return;
        }
        $environment->forceFill(['head' => $head])->save();
        Activity::record($project->workspace, 'project.preview_update', "#{$number} in {$project->name}", user: $user, meta: ['commit' => substr($head, 0, 7)]);
    }

    /**
     * The pull request closed or merged: remove its environment and what's
     * in it. Always, even with previews since turned off: a preview left
     * behind would keep running with nobody looking at it.
     */
    public function closed(Project $project, int $number): void
    {
        $environment = $this->find($project, $number);
        $user = $this->actor($project) ?? $project->workspace->owner;
        if ($environment === null || $user === null) {
            return;
        }
        Activity::record($project->workspace, 'project.preview_closed', "#{$number} in {$project->name}", user: $user);
        $this->environments->remove($environment, true, $user);
    }

    /** Remove every open preview: previews were turned off, or moved to another repository. */
    public function closeAll(Project $project, User $user): int
    {
        $open = $project->environments()->whereNotNull('pull_request')->get();
        foreach ($open as $environment) {
            Activity::record($project->workspace, 'project.preview_closed', "#{$environment->pull_request} in {$project->name}", user: $user);
            $this->environments->remove($environment, true, $user);
        }

        return $open->count();
    }

    public function find(Project $project, int $number): ?ProjectEnvironment
    {
        return $project->environments()->where('pull_request', $number)->first();
    }

    /** Who previews act as: whoever turned them on, while they can still manage machines. */
    private function actor(Project $project): ?User
    {
        $user = User::query()->find(self::settings($project)['user_id']);

        return $user !== null && $project->workspace->allows($user, WorkspacePermission::ManageMachines) ? $user : null;
    }

    /**
     * A copied app's deploy for a preview: the pull request's code if it
     * comes from the project's repository, its own address if previews have
     * a domain, and none of Production's secrets: anyone who can push a
     * branch can read a preview's settings from its code. Laravel's
     * APP_KEY gets a new one so the app still starts.
     *
     * @param  array<string, mixed>  $spec
     * @param  array{repo: string, domain: string|null}  $settings
     * @param  list<string>  $addresses  ones already given in this preview
     * @return array<string, mixed>
     */
    private function adjust(array $spec, ProjectNode $original, array $settings, int $number, string $head, array &$addresses): array
    {
        if (self::repoOf($spec) === strtolower($settings['repo'])) {
            $spec['commit'] = $head;
        }
        if ($settings['domain'] !== null) {
            $address = self::address($original->name, $number, $settings['domain']);
            for ($i = 2; in_array($address, $addresses, true); $i++) {
                $address = self::address("{$original->name}-{$i}", $number, $settings['domain']);
            }
            $addresses[] = $address;
            $spec['domains'] = [$address];
        }

        $env = array_map('strval', (array) ($spec['env'] ?? []));
        foreach (array_keys($env) as $name) {
            if (self::secret($name)) {
                unset($env[$name]);
            }
        }
        if (array_key_exists('APP_KEY', (array) ($spec['env'] ?? []))) {
            $env['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }
        $spec['env'] = (object) $env;

        return $spec;
    }

    /** A setting named like a secret: keys, tokens, passwords, credentials. */
    public static function secret(string $name): bool
    {
        return preg_match('/(KEY|SECRET|TOKEN|PASSWORD|PASS|PWD|CREDENTIAL|PRIVATE|DSN|AUTH|SALT|SIGNING)/i', $name) === 1;
    }

    /**
     * Where an app's preview opens: shop-pr-12.preview.example.com. The
     * app's part is cut short, never the pull request's number, to keep
     * the first label within DNS's 63 characters.
     */
    public static function address(string $app, int $number, string $domain): string
    {
        $suffix = "-pr-{$number}";

        return trim(substr($app, 0, 63 - strlen($suffix)), '-').$suffix.'.'.$domain;
    }
}
