<?php

namespace App\Mcp\Tools\Deploy;

use App\Http\Controllers\AppVersionController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\DeployFailure;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What the deploy tools share: queueing a deploy as the person (refused
 * while the machine is offline, noted in activity as done through MCP),
 * finding an app by the name it was deployed as, and how a deploy looks
 * to an agent, with no settings values or upload addresses in it.
 */
final class Deploys
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** Why the machine can't take a deploy now, as a ToolError. */
    public function requireAvailable(Machine $machine): void
    {
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new ToolError($reason);
        }
    }

    /**
     * Queue a deploy as the person, as the dashboard does.
     *
     * @param  array<string, mixed>  $job  the daemon's deploy spec
     */
    public function deploy(McpContext $context, Machine $machine, array $job): DaemonJob
    {
        try {
            return $context->within(fn () => $this->queue->deploy($machine, $context->user, $job));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /**
     * Send a kept deploy again, exactly as it was sent.
     */
    public function retry(McpContext $context, DaemonJob $job): DaemonJob
    {
        try {
            return $context->within(fn () => $this->queue->retry($job, $context->user));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /**
     * The name an app was deployed as, from what the agent said: that name,
     * "app:<name>", or one of its services (a container key, its name or
     * label). Anything ServerOS didn't deploy is refused.
     */
    public static function appName(McpContext $context, Machine $machine, mixed $reference): string
    {
        $reference = trim((string) $reference);
        $name = str_starts_with($reference, 'app:') ? substr($reference, 4) : $reference;
        if ($name === '') {
            throw new ToolError('Say which app: the name it was deployed as (list_deploys shows them).');
        }

        if (DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $name)->exists()) {
            return $name;
        }

        try {
            $service = $context->service($machine, $reference);
        } catch (ToolError) {
            $service = null;
        }
        $project = (string) ($service['details']['compose_project'] ?? '');
        $found = $service['app'] ?? (str_starts_with($project, 'serveros-') ? substr($project, 9) : null);
        if (is_string($found) && $found !== '') {
            return $found;
        }

        $known = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')
            ->whereNotNull('service')->distinct()->orderBy('service')->limit(20)->pluck('service');

        throw new ToolError($service !== null
            ? "{$service['display']} on {$machine->name} wasn’t deployed by ServerOS, so it has no deploys to work with."
            : "Nothing called “{$name}” was deployed on {$machine->name}.".($known->isNotEmpty() ? ' Deployed there: '.$known->implode(', ').'.' : ' list_deploys shows what was.'));
    }

    /** The latest successful deploy of an app built from code. */
    public static function lastCodeDeploy(Machine $machine, string $app): ?DaemonJob
    {
        return AppVersionController::lastDeployOf($machine, $app);
    }

    /** The latest successful deploy of anything under that name: an app or a template stack. */
    public static function lastDeploy(Machine $machine, string $app): ?DaemonJob
    {
        return self::lastCodeDeploy($machine, $app) ?? DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $app)
            ->where('state', 'succeeded')
            ->latest()
            ->first();
    }

    /** The code came from a .zip uploaded to the panel. */
    public static function fromUpload(?string $repo): bool
    {
        return str_contains((string) $repo, '/api/deploy-sources/');
    }

    /**
     * Where the code came from, as it may be shown: a Git repository's
     * address, or null for an upload (its address is the key to it).
     */
    public static function publicRepo(?string $repo): ?string
    {
        return blank($repo) || self::fromUpload($repo) ? null : $repo;
    }

    /**
     * Something on the machine already goes by that name: deploying under it
     * replaces it. Says what, or null.
     */
    public static function existing(Machine $machine, string $service): ?string
    {
        $active = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')
            ->where('service', $service)->whereIn('state', ['queued', 'running'])->latest()->first();
        if ($active !== null) {
            return "A deploy of {$service} is already under way on {$machine->name} (job {$active->id}).";
        }

        foreach ($machine->inventory['services'] ?? [] as $found) {
            if (! is_array($found)) {
                continue;
            }
            $release = AppReleases::parse($found['name'] ?? null);
            if (($release['app'] ?? null) === $service || ($found['details']['compose_project'] ?? null) === "serveros-{$service}") {
                return "{$service} is already deployed on {$machine->name}.";
            }
        }

        return null;
    }

    /**
     * A deploy job as an agent sees it: no settings values (they can be
     * secrets) and no upload address (it's the key to the code).
     *
     * @param  array<string, string>  $liveIds  app name => id of its live deploy
     * @return array<string, mixed>
     */
    public static function present(DaemonJob $job, McpContext $context, array $liveIds = [], int $logLines = 0): array
    {
        $spec = $job->command['job'] ?? null;
        $repo = is_array($spec) ? ($spec['repo'] ?? null) : null;
        $fromCode = filled($repo);
        $machine = $job->relationLoaded('machine') ? $job->machine : null;

        $row = [
            'id' => $job->id,
            'machine' => $machine === null ? ['id' => $job->machine_id] : ['id' => $machine->id, 'name' => $machine->name],
            'app' => $job->service,
            'state' => $job->state,
            'finished' => in_array($job->state, McpContext::FINISHED, true),
            'phase' => $job->phase,
            'progress' => $job->progress,
            'kind' => match (true) {
                ! is_array($spec) => null,
                $fromCode => 'app',
                default => 'stack',
            },
            'source' => match (true) {
                ! $fromCode => null,
                self::fromUpload($repo) => 'upload',
                default => 'git',
            },
            'repo' => self::publicRepo($repo),
            'commit' => $fromCode ? ($spec['commit'] ?? null) : null,
            'domains' => is_array($spec) ? array_values($spec['domains'] ?? []) : [],
            'port' => is_array($spec) ? ($spec['port'] ?? null) : null,
            'live' => ($liveIds[$job->service] ?? null) === $job->id,
            'by' => $job->startedByName(),
            'error' => $job->error === null ? null : array_filter([
                'phase' => $job->error['phase'] ?? null,
                'message' => $job->error['message'] ?? null,
                'nextStep' => $job->error['next_step'] ?? null,
                // The cause in plain words and what fixes it, when it's a common one.
                'explanation' => DeployFailure::explain($job),
            ]),
            'retryable' => $job->isRetryable(),
            'stale' => $job->isStale(),
            'createdAt' => $job->created_at?->toIso8601String(),
            'finishedAt' => $job->finished_at?->toIso8601String(),
            'url' => $context->url("/machines/{$job->machine_id}?job={$job->id}"),
        ];
        if ($logLines > 0) {
            $log = array_values($job->log ?? []);
            $row['log'] = array_slice($log, -$logLines);
            $row['logLines'] = count($log);
        }

        return $row;
    }

    /**
     * Which upload a deploy's code is, if it is one and it's still on hand.
     */
    public static function upload(?string $repo): ?DeploySource
    {
        return self::fromUpload($repo) ? DeploySource::fromRepoUrl($repo) : null;
    }

    /**
     * A validation failure from code the dashboard shares, in words: each
     * setting's message under its name.
     */
    public static function explain(ValidationException $e): string
    {
        $lines = [];
        foreach ($e->validator->errors()->messages() as $key => $messages) {
            $message = (string) Arr::first($messages);
            $lines[] = str_starts_with($key, 'env.') ? substr($key, 4).': '.$message : $message;
        }

        return implode(' ', $lines);
    }

    /** Run shared deploy checks, turning their validation errors into words for the agent. */
    public static function check(Closure $check): mixed
    {
        try {
            return $check();
        } catch (ValidationException $e) {
            throw new ToolError(self::explain($e));
        }
    }

    /** @return array<string, mixed> */
    public static function waitSchema(): array
    {
        return Schema::integer('Seconds to wait before answering (0–25, default 0). Deploys usually take a few minutes, so this mostly catches early failures: follow the job with get_job or list_deploys.', 0, McpContext::MAX_WAIT_SECONDS);
    }

    /** @return list<string> */
    public static function waitRules(): array
    {
        return ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS];
    }

    /**
     * A rule for references to machines and apps: an id or a name.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }

    /**
     * Messages for the shared deploy rules, naming the tools' arguments.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'service.required' => 'Give the name to deploy it as, like my-app.',
            'service.regex' => 'name must start with a letter and use only lowercase letters, digits and dashes, like my-app.',
            'service.max' => 'name can be at most 63 characters.',
            'repo.required' => 'Give the repository’s HTTPS address, like https://github.com/you/app.git.',
            'repo.url' => 'repo must be a URL, like https://github.com/you/app.git.',
            'commit.required' => 'Give the commit to deploy (ref).',
            'commit.regex' => 'ref must be a branch, a tag or a full 40-character commit.',
            'port.required' => 'Give the port the app listens on inside its container.',
            'port.between' => 'port must be between 1 and 65535.',
            'port.integer' => 'port must be a whole number.',
            'health_path.regex' => 'health_path must be a path starting with /, like /health.',
            'domain.regex' => 'Enter a domain like app.example.com.',
            'env.array' => 'env must be an object of setting names to values.',
            'env.*.max' => 'Each setting can be at most 2000 characters.',
            'env.*.string' => 'Each setting must be text (or true/false for the ones to accept).',
            'extra_env.max' => 'extra_env can be at most 10000 characters.',
        ];
    }
}
