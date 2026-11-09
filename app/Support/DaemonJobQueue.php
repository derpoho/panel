<?php

namespace App\Support;

use App\Events\DeployQueued;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DaemonRelease;
use App\Models\DeploySource;
use App\Models\ImpersonationView;
use App\Models\Machine;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Deploys\AppDatabase;
use App\Support\GitHub\GitHubApp;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Records a daemon job and hands it to the gateway. The command is kept,
 * encrypted, on the job so a deploy can be retried exactly as it was sent.
 */
class DaemonJobQueue
{
    /** What a workspace without a plan can't send: deploys and the console. */
    public const array NEEDS_PLAN = ['deploy', 'release', 'rollback', 'service_exec', 'service_clone'];

    /** Jobs that only look: logs, scans, files opened, a check for updates. */
    private const array READS = ['service_logs', 'service_inspect', 'discover', 'file_list', 'file_read', 'package_updates', 'disk_usage'];

    public function __construct(private readonly DaemonGatewayClient $gateway) {}

    /**
     * Why this machine can't take commands right now, or null if it can.
     * With the kind of job, also whether the workspace's plan allows it.
     */
    public function unavailable(Machine $machine, ?string $kind = null): ?string
    {
        if (! config('daemon.commands_enabled')) {
            return 'Sending work to machines isn’t turned on for this panel.';
        }

        if ($kind !== null && ($reason = self::needsPlan($machine, $kind)) !== null) {
            return $reason;
        }

        if ($machine->uid === null || $machine->disconnected_at !== null || $machine->last_seen_at === null || $machine->last_seen_at->lt(now()->subSeconds(60))) {
            return "{$machine->name} is offline, so it can’t take work right now. Check the machine is on and the ServerOS daemon is running.";
        }

        return null;
    }

    /**
     * Why the workspace can't send this kind of job without a plan, or null.
     * Everything already running keeps running; this only stops new deploys
     * and console commands.
     */
    public static function needsPlan(Machine $machine, string $kind): ?string
    {
        if (! in_array($kind, self::NEEDS_PLAN, true) || $machine->workspace->isActive()) {
            return null;
        }

        return $kind === 'service_exec'
            ? 'The console needs a plan. Choose one in Billing to run commands again.'
            : 'Deploys need a plan. Choose one in Billing to deploy again. Everything that’s already running keeps running.';
    }

    /**
     * @param  array<string, mixed>  $job  the daemon deploy spec
     *
     * @throws RuntimeException when the gateway refuses it; the job is then
     *                          recorded as refused
     */
    public function deploy(Machine $machine, User $user, array $job): DaemonJob
    {
        // The app's own setting, whichever way the deploy was started, and
        // its database, so a deploy that breaks its data can be undone.
        $job['snapshot_before'] = $machine->snapshotsBeforeDeploy((string) $job['service']);
        $database = $job['snapshot_before'] ? AppDatabase::for($machine, (string) $job['service'], (array) ($job['env'] ?? [])) : null;
        $job['snapshot_also'] = $database === null ? [] : [$database['key']];

        $record = $this->queue($machine, $user, 'deploy', $job, $job['service'], 1800);
        // Listeners act on it as it's sent, such as routing its domains.
        event(new DeployQueued($machine, $record, (array) ($job['domains'] ?? [])));

        return $record;
    }

    /**
     * Record any daemon job and send it. `$service` is what the panel files
     * it under: a deploy's name, or a discovered service's key.
     *
     * @param  array<string, mixed>  $job
     * @param  array{kind: string, job: array<string, mixed>, service: string|null}|null  $then  a job to queue once this one succeeds
     *
     * @throws RuntimeException when the gateway refuses it
     */
    public function queue(Machine $machine, User $user, string $kind, array $job, ?string $service, int $timeoutSecs = 300, ?array $then = null): DaemonJob
    {
        if (($reason = self::needsPlan($machine, $kind)) !== null) {
            throw new RuntimeException($reason);
        }

        $session = AuditContext::viewAsSession($user);
        $command = [
            'actor' => self::actor($user, $session),
            'confirmed' => true,
            'timeout_secs' => $timeoutSecs,
            'job' => $job,
        ];

        $record = DaemonJob::query()->create([
            'id' => (string) Str::uuid(),
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'impersonation_session_id' => $session,
            'kind' => $kind,
            'command' => $command,
            'service' => $service,
            'state' => 'queued',
            'log' => [],
            'mcp_call_id' => self::callId(),
            'then' => $then,
        ]);

        $this->send($machine, $record, $command);
        $this->recordActivity($machine, $user, $kind, $job, $service);

        return $record;
    }

    /**
     * A job finished: if it succeeded and something was waiting on it, queue
     * that now, as the person who asked. Runs once: the step is cleared first.
     */
    public function followUp(DaemonJob $job): ?DaemonJob
    {
        $then = $job->then;
        if ($then === null || $job->state !== 'succeeded') {
            return null;
        }
        $job->forceFill(['then' => null])->saveQuietly();

        $user = $job->user;
        if ($user === null) {
            return null;
        }

        // Asked for by the same call as the job it waited on.
        $from = $job->mcp_call_id === null ? [] : ['via' => 'mcp', 'call' => $job->mcp_call_id];

        try {
            return AuditContext::within($from, fn () => $this->queue($job->machine, $user, $then['kind'], $then['job'], $then['service']));
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }
    }

    /**
     * What people do to services goes in the workspace's activity. Reads
     * (logs, scans, files opened) don't; console commands do, word for
     * word, since they can change anything, and so do files saved or
     * deleted.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $meta
     */
    private function recordActivity(Machine $machine, User $user, string $kind, array $job, ?string $service, array $meta = []): void
    {
        $action = match ($kind) {
            'deploy', 'release' => 'service.deploy',
            'rollback' => 'service.rollback',
            'service_action' => in_array($job['action'] ?? null, ['start', 'stop', 'restart', 'reload'], true) ? 'service.'.$job['action'] : null,
            'service_exec' => 'console.command',
            'adopt' => 'service.adopt',
            'service_remove' => 'service.remove',
            'service_repair' => 'service.repair',
            'service_clone' => 'service.clone',
            'file_write' => 'file.write',
            'file_delete' => 'file.delete',
            default => null,
        };
        if ($action === null) {
            // A read or a check changes nothing (the job itself names who
            // started it). Anything else the caller records in its own
            // words: a reboot, a firewall rule, a restore, a backup
            // receiver's setup.
            // (An update check is a read; installing them, `apply`, isn't.)
            if (in_array($kind, self::READS, true) && ! ($job['apply'] ?? false)) {
                AuditContext::nothingChanged();
            }
            // Files opened while viewing as someone go in that session's
            // pages, by path only, since they're not a page of their own.
            if (in_array($kind, ['file_list', 'file_read'], true) && ($session = AuditContext::viewAsSession($user)) !== null) {
                ImpersonationView::query()->create([
                    'impersonation_session_id' => $session,
                    'route' => 'workspace.machines.files',
                    'parameters' => ['machine' => (string) $machine->id, 'path' => mb_substr((string) ($job['path'] ?? ''), 0, 500)],
                ]);
            }

            return;
        }

        // Files are named by their path; what's written isn't kept.
        if (str_starts_with($kind, 'file_')) {
            $bytes = isset($job['content_b64']) ? strlen((string) base64_decode((string) $job['content_b64'])) : null;
            Activity::record($machine->workspace_id, $action, (string) ($job['path'] ?? ''), $machine, $user, array_filter(['bytes' => $bytes], fn ($v) => $v !== null));

            return;
        }

        $name = $service;
        $found = null;
        foreach ($machine->inventory['services'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $service && is_string($candidate['name'] ?? null)) {
                $name = $candidate['name'];
                $found = $candidate;
                break;
            }
        }

        // A deploy of an uploaded .zip names the file, not our git address.
        if ($kind === 'deploy' && preg_match('#/api/deploy-sources/(\w+)\.git$#', (string) ($job['repo'] ?? ''), $m) === 1
            && is_string($file = DeploySource::query()->where('token', $m[1])->value('filename'))) {
            $meta['upload'] = $file;
        }
        if ($kind === 'service_clone') {
            $meta += array_filter(['as' => $job['name'] ?? null, 'data' => (bool) ($job['copy_data'] ?? false)], fn ($v) => $v !== null);
        }
        if ($kind === 'rollback') {
            $meta['commit'] = substr((string) ($job['release'] ?? ''), 0, 7);
            $meta['data'] = ($job['restore'] ?? []) !== [];
        }
        if ($kind === 'release') {
            $meta['commit'] = substr((string) ($job['commit'] ?? ''), 0, 7);
        }
        if ($kind === 'service_exec') {
            $meta['command'] = mb_substr((string) ($job['command'] ?? ''), 0, 500);
        }
        if ($kind === 'service_remove' && ($job['delete_data'] ?? false)) {
            $meta['deleted_data'] = true;
        }
        if ($kind === 'service_remove' && $found !== null && ($found['origin'] ?? null) !== 'created') {
            $meta['adopted'] = true;
        }

        Activity::record($machine->workspace_id, $action, $name, $machine, $user, $meta);
    }

    /**
     * Who the machine's own audit log names: the person, or ServerOS
     * Support when staff are viewing as them.
     *
     * @return array{kind: string, name: string, id: string}
     */
    /**
     * Who the job is for, and where the request came from: an MCP or API
     * call (via, client, tool, call id) or a workflow run. The daemon writes
     * these into actions.log, so each line traces back to what asked.
     *
     * @return array<string, string>
     */
    private static function actor(User $user, ?int $viewAs): array
    {
        $from = AuditContext::via();
        $workflow = ($from['via'] ?? null) === 'workflow';

        return array_filter([
            'kind' => 'user',
            'name' => $viewAs === null ? $user->email : AuditContext::SUPPORT,
            'id' => (string) $user->id,
            'via' => self::text($from['via'] ?? null),
            'client' => self::text($workflow ? ($from['workflow'] ?? null) : ($from['client'] ?? null)),
            'tool' => self::text($from['tool'] ?? null),
            'call' => self::text($workflow && isset($from['run']) ? 'run-'.$from['run'] : ($from['call'] ?? null)),
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 120) : null;
    }

    /** The MCP or API call queueing a job, when there is one. */
    private static function callId(): ?string
    {
        $from = AuditContext::via();

        return in_array($from['via'] ?? null, ['mcp', 'api'], true) && is_string($from['call'] ?? null) ? $from['call'] : null;
    }

    /**
     * Send a kept deploy again, as a new job with the same command.
     */
    public function retry(DaemonJob $job, User $user): DaemonJob
    {
        $command = $job->command ?? throw new RuntimeException('This deploy can’t be retried.');
        if (($reason = self::needsPlan($job->machine, $job->kind)) !== null) {
            throw new RuntimeException($reason);
        }
        $session = AuditContext::viewAsSession($user);
        $command['actor'] = self::actor($user, $session);

        $record = DaemonJob::query()->create([
            'id' => (string) Str::uuid(),
            'machine_id' => $job->machine_id,
            'user_id' => $user->id,
            'impersonation_session_id' => $session,
            'kind' => $job->kind,
            'command' => $command,
            'service' => $job->service,
            'state' => 'queued',
            'log' => [],
            'mcp_call_id' => self::callId(),
        ]);

        $this->send($job->machine, $record, $command);
        $this->recordActivity($job->machine, $user, $job->kind, $command['job'] ?? [], $job->service, ['retry' => true]);

        return $record;
    }

    /** The job fields the daemon reads as maps (name to value). */
    private const array MAPS = ['files', 'env'];

    /**
     * Sends a job's map fields as JSON objects. A kept job comes back from
     * the database as PHP arrays, where an empty map is just [] and would go
     * out as a JSON list; the daemon can't read a deploy whose files are a
     * list and drops it unanswered, so a redeploy of an app sent with no
     * files would sit queued forever.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private static function withMaps(array $command): array
    {
        foreach (self::MAPS as $field) {
            if (isset($command['job'][$field]) && is_array($command['job'][$field])) {
                $command['job'][$field] = (object) $command['job'][$field];
            }
        }

        return $command;
    }

    /**
     * A deploy of a private GitHub repository the workspace has connected
     * goes with a fresh token that can read just that repository. It's
     * added as the job is sent, never to what's kept, so a retry gets a
     * new one and the database never holds one.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function withRepoToken(Machine $machine, array $command): array
    {
        $repo = $command['job']['repo'] ?? null;
        if (($command['job']['type'] ?? null) !== 'deploy' || ! is_string($repo)
            || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $repo, $m)) {
            return $command;
        }

        $token = app(GitHubApp::class)->tokenFor($machine->workspace, $m[1], $m[2]);
        if ($token === null) {
            return $command;
        }
        if (! self::canFetchPrivate($machine)) {
            throw new RuntimeException("{$machine->name}’s daemon is too old to deploy private repositories. Update it with “sudo serverosd update”, then deploy again.");
        }
        $command['job']['repo_token'] = $token;

        return $command;
    }

    /** Whether the machine's daemon can fetch with a repository token. */
    public static function canFetchPrivate(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.repo_token_since'));
    }

    /** Whether the machine's daemon can show a container's docker inspect. */
    public static function canInspect(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.inspect_since'));
    }

    /** Whether the machine's daemon can skip services and lines in its logs. */
    public static function canSkipLogs(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.log_skip_since'));
    }

    /**
     * Whether the machine's daemon follows every part of a machine policy:
     * discovery and logs off, updates waiting for approval.
     */
    public static function canFollowPolicy(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.policy_since'));
    }

    /** Whether the machine's daemon can mask IP addresses in log lines. */
    public static function canMaskIps(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.log_ip_masking_since'));
    }

    /** Whether the machine's daemon can remove a systemd service. */
    public static function canRemoveSystemd(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.systemd_remove_since'));
    }

    /** Whether the machine's daemon can run a command given settings by name. */
    public static function canProjectData(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.project_data_since'));
    }

    /** Whether the machine's daemon can clone a container. */
    public static function canClone(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.clone_since'));
    }

    /**
     * Whether the machine's daemon can install exact package versions and
     * hold packages at theirs.
     */
    public static function canInstallVersions(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.package_versions_since'));
    }

    /** Whether the machine's daemon can install packages it doesn't have yet. */
    public static function canAddPackages(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.package_add_since'));
    }

    /**
     * Ask the machine to look at what's running now rather than waiting for
     * its hourly scan. A scan already under way is reused, so repeated asks
     * don't pile up. Null when the machine can't take work or can't be
     * reached: what it last reported stands.
     */
    public function rescan(Machine $machine, User $user): ?DaemonJob
    {
        if ($this->unavailable($machine) !== null) {
            return null;
        }

        $running = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'discover')
            ->whereIn('state', ['queued', 'running'])
            ->where('created_at', '>', now()->subMinutes(2))
            ->latest()
            ->first();

        try {
            return $running ?? $this->queue($machine, $user, 'discover', ['type' => 'discover'], null, 120);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Whether the machine's daemon can serve wildcard subdomains, each with
     * its own certificate as it's first visited.
     */
    public static function canWildcard(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.wildcard_domains_since'));
    }

    /** Whether the machine's daemon can say what's using its disk. */
    public static function canScanDisk(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.disk_usage_since'));
    }

    /** Whether a stable release that can inspect is out, to update to. */
    public static function inspectReleased(): bool
    {
        $latest = DaemonRelease::latest('stable', 'amd64')?->version;

        return $latest !== null && DaemonRelease::compare($latest, (string) config('daemon.inspect_since')) >= 0;
    }

    private static function atLeast(Machine $machine, string $since): bool
    {
        $version = ltrim((string) $machine->daemon_version, 'v');
        // A build from source on a developer's machine has whatever's newest.
        if (str_ends_with($version, '-local')) {
            return true;
        }

        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1
            && DaemonRelease::compare($version, $since) >= 0;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function send(Machine $machine, DaemonJob $record, array $command): void
    {
        try {
            $this->gateway->dispatch($machine, $record->id, $this->withRepoToken($machine, self::withMaps($command)));
        } catch (RuntimeException $e) {
            $record->forceFill([
                'state' => 'refused',
                'error' => ['phase' => 'queue', 'message' => $e->getMessage()],
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
