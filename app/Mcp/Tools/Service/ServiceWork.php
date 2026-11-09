<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use Closure;
use RuntimeException;

/**
 * What the service tools share: finding a machine and one of its services,
 * the checks the service page makes before it acts (adopted first,
 * containers only, machine online), sending the job the way the dashboard
 * does, and the argument rules they repeat.
 */
final class ServiceWork
{
    /** The jobs a service page lists. */
    public const SERVICE_JOBS = ['service_action', 'service_logs', 'service_exec', 'service_remove', 'service_repair', 'adopt'];

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * The machine and the service the arguments name, as the service page
     * sees it.
     *
     * @param  array<string, mixed>  $data  validated, with machine and service
     * @return array{0: Machine, 1: array<string, mixed>}
     */
    public static function find(McpContext $context, array $data): array
    {
        $machine = $context->machine($data['machine']);

        return [$machine, $context->service($machine, $data['service'])];
    }

    /** A server job on one of this workspace's machines, by its id. */
    public static function job(McpContext $context, mixed $id): DaemonJob
    {
        $id = trim((string) $id);
        $job = preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1
            ? DaemonJob::query()->with(['machine', 'user'])->whereKey(strtolower($id))->whereIn('machine_id', $context->workspace->machines()->select('id'))->first()
            : null;

        return $job ?? throw new ToolError("No job “{$id}” in {$context->workspace->name}. Job ids come from the tools that start them, and list_jobs.");
    }

    /** Why the machine can't take work now (this kind of job, if given), or null. */
    public function unavailable(Machine $machine, ?string $kind = null): ?string
    {
        return $this->queue->unavailable($machine, $kind);
    }

    /**
     * Send a job for a service as the person, like the service page's
     * actions: refused while the machine is offline. The activity entry the
     * queue writes for it is marked as done through MCP.
     *
     * @param  array<string, mixed>  $job
     */
    /**
     * @param  array<string, mixed>  $job
     * @param  array{kind: string, job: array<string, mixed>, service: string|null}|null  $then  a job to queue once this one succeeds
     */
    public function queue(McpContext $context, Machine $machine, string $kind, array $job, ?string $service, ?array $then = null, int $timeoutSecs = 300): DaemonJob
    {
        if (($reason = $this->queue->unavailable($machine, $kind)) !== null) {
            throw new ToolError($reason);
        }

        try {
            return $context->within(fn () => $this->queue->queue($machine, $context->user, $kind, $job, $service, $timeoutSecs, $then));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /** Send a kept deploy again, as DaemonJobController::retry does. */
    public function retry(McpContext $context, DaemonJob $job): DaemonJob
    {
        try {
            return $context->within(fn () => $this->queue->retry($job, $context->user));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /**
     * Only adopted services can be controlled, as on the service page.
     *
     * @param  array<string, mixed>  $service
     */
    public static function requireManaged(array $service): void
    {
        if (! ($service['managed'] ?? false)) {
            throw new ToolError('Adopt this service first so ServerOS can control it (adopt_service, once the person agrees).');
        }
    }

    /** @param  array<string, mixed>  $service */
    public static function requireContainer(array $service): void
    {
        if (! ($service['container'] ?? false)) {
            throw new ToolError('That only works for container services.');
        }
    }

    /**
     * The person's go-ahead: the name typed exactly, like the dashboard's
     * type-to-confirm.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $accepted  names that count; the first is the one asked for
     */
    public static function confirm(array $arguments, array $accepted, string $what, string $because = 'This can’t be undone.'): void
    {
        $given = $arguments['confirm'] ?? null;
        $name = $accepted[0];
        if (! is_string($given) || trim($given) === '') {
            throw new ToolError("{$because} Check with the person first, then call again with confirm set to the {$what}’s name exactly: “{$name}”.");
        }
        if (! in_array(trim($given), $accepted, true)) {
            throw new ToolError("confirm doesn’t match. It must be the {$what}’s name exactly: “{$name}”. Nothing was changed.");
        }
    }

    /**
     * How a service group remembers a service: a deployed app by its name,
     * which survives redeploys; anything else by its key.
     *
     * @param  array<string, mixed>  $service
     */
    public static function memberKey(array $service): string
    {
        return filled($service['app'] ?? null) ? 'app:'.$service['app'] : (string) $service['key'];
    }

    /**
     * A rule for references to machines, services and groups: an id, key
     * or name.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id, a key or a name.");
            }
        };
    }

    /** @return array<string, mixed> */
    public static function waitSchema(int $default = 0): array
    {
        return Schema::integer("Seconds to wait for the job to finish before answering (0–25, default {$default}). Jobs that take longer keep running: follow them with get_job.", 0, McpContext::MAX_WAIT_SECONDS);
    }

    /** @return list<string> */
    public static function waitRules(): array
    {
        return ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS];
    }

    /** @return array<string, mixed> */
    public static function serviceArguments(): array
    {
        return [
            'machine' => ['required', self::reference()],
            'service' => ['required', self::reference()],
        ];
    }
}
