<?php

namespace App\Mcp;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Audit\AuditContext;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ContainerNames;
use App\Support\Deploys\ServiceLabels;
use App\Support\StatusPages\StatusReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who's calling and where: the workspace the connection is for, the
 * person it acts as, and what it may do. Tools look things up through it
 * so a reference ("web-1", "postgres") resolves the same everywhere, and
 * only ever inside this workspace.
 */
final class McpContext
{
    /** Job states that mean it's over. */
    public const FINISHED = ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];

    /** Longest a tool may wait on the server before answering. */
    public const MAX_WAIT_SECONDS = 25;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
        public readonly ?McpToken $token = null,
        /** How the call came: mcp, api for the REST API, or assistant for the panel's Ask AI. */
        public readonly string $via = 'mcp',
        /**
         * What it may do when there's no token to say: Ask AI reads and
         * changes, never runs commands (those go through Fixes, with
         * two-factor).
         *
         * @var list<string>|null
         */
        private readonly ?array $grantedScopes = null,
    ) {}

    /** The tool this call runs, and its id: set as it starts (startCall). */
    public ?string $tool = null;

    public ?string $callId = null;

    /**
     * The person's role here, looked up once: deciding which tools they may
     * use checks every tool's permission.
     *
     * @var array{role: ?WorkspaceRole, custom: ?WorkspaceCustomRole}|null
     */
    private ?array $access = null;

    /**
     * A tool call starts: give it an id, which the server jobs it queues and
     * the daemon's actions.log lines for them carry. The role is looked up
     * fresh, so a change an earlier call made to it counts.
     */
    public function startCall(string $tool): void
    {
        $this->tool = $tool;
        $this->callId = (string) Str::uuid();
        $this->access = null;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->token->scopes ?? $this->grantedScopes ?? Scopes::ALL;
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    public function can(WorkspacePermission $permission): bool
    {
        $this->access ??= $this->workspace->accessOf($this->user);

        return Workspace::grants($this->access, $permission);
    }

    public function require(WorkspacePermission $permission): void
    {
        if (! $this->can($permission)) {
            throw new ToolError("Your role in {$this->workspace->name} doesn’t allow that ({$permission->label()}). Ask an owner or admin.");
        }
    }

    /** What the connection is called: the AI app for OAuth, the key's name otherwise. */
    public function client(): string
    {
        return $this->token?->label() ?? match ($this->via) {
            'api' => 'API',
            'assistant' => 'Ask AI',
            default => 'MCP',
        };
    }

    /**
     * A machine in this workspace, by id or name (any case). Several with
     * the same name: the agent has to say which.
     */
    public function machine(mixed $reference): Machine
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which machine: its id or name. list_machines shows them.');
        }

        $query = $this->workspace->machines();
        if (ctype_digit($reference) && ($machine = (clone $query)->find((int) $reference)) !== null) {
            return $machine;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several machines are called “{$reference}”: ".$matches->map(fn (Machine $m) => "#{$m->id}")->implode(', ').'. Use the id.');
        }

        $close = (clone $query)->whereRaw('lower(name) like ?', ['%'.mb_strtolower($reference).'%'])->limit(5)->pluck('name');

        throw new ToolError("No machine “{$reference}” in {$this->workspace->name}.".($close->isNotEmpty() ? ' Did you mean: '.$close->implode(', ').'?' : ' list_machines shows them.'));
    }

    /**
     * A service on a machine from its latest scan, by key, the label it
     * was given, its name, or a deployed app's name ("app:shop" or "shop").
     * Comes back the way the service page sees it.
     *
     * @return array<string, mixed>
     */
    public function service(Machine $machine, mixed $reference): array
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which service: its key, name or label. list_services shows them.');
        }

        if (str_starts_with($reference, 'app:') && ($app = AppReleases::find($machine, substr($reference, 4))) !== null) {
            return $this->describeService($machine, $app);
        }

        $services = $this->services($machine);
        $lower = mb_strtolower($reference);
        foreach ([
            fn (array $s) => ($s['key'] ?? null) === $reference,
            fn (array $s) => mb_strtolower((string) (ServiceLabels::for($machine, $s) ?? '')) === $lower,
            fn (array $s) => mb_strtolower(ltrim((string) ($s['name'] ?? ''), '/')) === $lower,
            fn (array $s) => mb_strtolower(ServiceLabels::display($machine, $s)) === $lower,
            // "nginx" for nginx.service; "Umami" for serveros-umami-umami-1.
            fn (array $s) => mb_strtolower(preg_replace('/\.service$/', '', ltrim((string) ($s['name'] ?? ''), '/')) ?? '') === $lower,
            fn (array $s) => mb_strtolower(StatusReport::friendlyName((string) ($s['name'] ?? ''))) === $lower,
        ] as $test) {
            $found = $services->filter($test);
            if ($found->count() === 1) {
                return $this->describeService($machine, $found->first());
            }
            if ($found->count() > 1) {
                throw new ToolError("Several services on {$machine->name} match “{$reference}”: ".$found->pluck('key')->implode(', ').'. Use the key.');
            }
        }

        // A deployed app by its plain name.
        if (($app = AppReleases::find($machine, $reference)) !== null) {
            return $this->describeService($machine, $app);
        }

        throw new ToolError("No service “{$reference}” on {$machine->name}. list_services shows what’s there.");
    }

    /**
     * Every service on the machine as the panel shows them: retired app
     * versions left out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function services(Machine $machine): Collection
    {
        return collect(self::keyed(AppReleases::visible($machine)));
    }

    /**
     * The inventory entries that have a key to find them by.
     *
     * @param  list<array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private static function keyed(array $services): array
    {
        return array_values(array_filter($services, fn (array $s) => is_string($s['key'] ?? null)));
    }

    /**
     * @param  array<string, mixed>  $service  an inventory entry
     * @return array<string, mixed>
     */
    public function describeService(Machine $machine, array $service): array
    {
        $release = AppReleases::parse($service['name'] ?? null);

        return [
            ...$service,
            'app' => $release !== null && isset(AppReleases::live($machine)[$release['app']]) ? $release['app'] : null,
            'label' => ServiceLabels::for($machine, $service),
            'found' => ContainerNames::found($service),
            'display' => ServiceLabels::display($machine, $service),
            'icon' => ServiceLabels::iconUrl($machine, $service),
            'managed' => ($service['details']['managed'] ?? null) === 'true',
            'container' => str_starts_with((string) $service['key'], 'docker:'),
        ];
    }

    /**
     * Note it in the workspace's activity, crediting the person and saying
     * it came through MCP or the API.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, ?string $subject = null, ?Machine $machine = null, array $meta = []): void
    {
        Activity::record($this->workspace, $action, $subject, $machine, $this->user, [...$meta, 'via' => $this->via, 'client' => $this->client()]);
    }

    /**
     * Do `$work` as done through MCP or the REST API: whatever it records
     * (the job queue notes deploys and service actions itself) says which,
     * and through what client.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(callable $work): mixed
    {
        return AuditContext::within(array_filter([
            'via' => $this->via,
            'client' => $this->client(),
            'tool' => $this->tool,
            'call' => $this->callId,
        ], fn ($value) => $value !== null), $work);
    }

    /**
     * Wait for a server job to finish, up to a limit, and return it as it
     * is then. Jobs that take longer keep going; get_job checks on them.
     */
    public function waitForJob(DaemonJob $job, int $seconds): DaemonJob
    {
        $deadline = microtime(true) + max(0, min(self::MAX_WAIT_SECONDS, $seconds));
        while (! in_array($job->state, self::FINISHED, true) && microtime(true) < $deadline) {
            usleep(500_000);
            $job->refresh();
        }

        return $job;
    }

    /** A page of this workspace in the panel. */
    public function url(string $path = '/'): string
    {
        return rtrim($this->workspace->url(), '/').'/'.ltrim($path, '/');
    }
}
