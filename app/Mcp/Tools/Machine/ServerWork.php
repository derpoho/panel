<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Machines\PackageUpdates;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * What the machine tools share: sending work to a server the way the
 * dashboard does (refused while it's offline, queued as the person, no
 * faster than its routes allow), the type-to-confirm check for steps that
 * can't be undone, and the argument rules and schema they repeat.
 */
final class ServerWork
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * Queue a daemon job on the machine, as the person.
     *
     * @param  array<string, mixed>  $job
     */
    public function queue(McpContext $context, Machine $machine, string $kind, array $job, int $timeout): DaemonJob
    {
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            throw new ToolError($reason);
        }

        try {
            return $context->within(fn () => $this->queue->queue($machine, $context->user, $kind, $job, null, $timeout));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /** Why the machine can't take work now, or null. */
    public function unavailable(Machine $machine): ?string
    {
        return $this->queue->unavailable($machine);
    }

    /**
     * An install of updates queued or running on the machine (not one gone
     * quiet), as MachineUpdatesController::busy() sees it.
     */
    public static function installing(Machine $machine): ?DaemonJob
    {
        return PackageUpdates::installing($machine);
    }

    /**
     * The arguments with these strings trimmed, as the dashboard's requests
     * arrive (TrimStrings), so length limits count the same characters.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function trimmed(array $arguments, string ...$keys): array
    {
        foreach ($keys as $key) {
            if (is_string($arguments[$key] ?? null)) {
                $arguments[$key] = trim($arguments[$key]);
            }
        }

        return $arguments;
    }

    /**
     * Hold to the same pace the dashboard's route allows (throttle:N,1),
     * per person, so an agent in a loop can't reboot a server ten times.
     */
    public static function throttle(McpContext $context, string $action, int $perMinute): void
    {
        $key = "mcp-machine:{$action}:{$context->user->id}";
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            throw new ToolError("That’s been done {$perMinute} times in the last minute, as often as the dashboard allows. Try again in {$seconds} seconds.");
        }
        RateLimiter::hit($key, 60);
    }

    /**
     * The person's go-ahead, as the dashboard's "type the name to confirm".
     * $warning is what to tell them first.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function confirm(array $arguments, string $name, string $what, string $warning = 'This can’t be undone.'): void
    {
        $given = $arguments['confirm'] ?? null;
        if (! is_string($given) || trim($given) === '') {
            throw new ToolError("{$warning} Check with the person first, then call again with confirm set to the {$what}’s name exactly: “{$name}”.");
        }
        if (trim($given) !== $name) {
            throw new ToolError("confirm doesn’t match. It must be the {$what}’s name exactly: “{$name}”. Nothing was changed.");
        }
    }

    /**
     * A rule for references to machines and groups: an id or a name.
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

    /** @return array<string, mixed> */
    public static function waitSchema(): array
    {
        return Schema::integer('Seconds to wait for the job to finish before answering (0–25, default 0). Jobs that take longer keep running: follow them with get_job.', 0, McpContext::MAX_WAIT_SECONDS);
    }

    /** @return list<string> */
    public static function waitRules(): array
    {
        return ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS];
    }
}
