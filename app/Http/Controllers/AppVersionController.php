<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppDatabase;
use App\Support\Deploys\AppVersions;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\ServiceLabels;
use App\Support\DeploySources\DeploySourcePacker;
use App\Support\Projects\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Changing an app ServerOS deployed from code: a new version (the last
 * deploy's settings with the code swapped for a fresh .zip), or a new
 * domain (the same code and settings, served somewhere else). Either way
 * the daemon builds it, checks it and only then switches over, so the old
 * version keeps serving if the new one fails.
 */
class AppVersionController extends Controller
{
    /**
     * The app deploy behind a container, from the name the daemon gives it
     * (serveros-<service>-<first 12 of the commit>), if it was built from code.
     */
    public static function lastDeploy(Machine $machine, ?string $container): ?DaemonJob
    {
        if (! preg_match('/^\/?serveros-(.+)-[0-9a-f]{12}$/', (string) $container, $match)) {
            return null;
        }

        return self::lastDeployOf($machine, $match[1]);
    }

    /**
     * The latest successful deploy of an app from code (a Git repository
     * or an upload), by the name it was deployed as.
     */
    public static function lastDeployOf(Machine $machine, string $service): ?DaemonJob
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $service)
            ->where('state', 'succeeded')
            ->latest()
            ->get()
            ->first(fn (DaemonJob $job) => filled($job->command['job']['repo'] ?? null));
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $token = $request->validate(['source' => ['required', 'string', 'size:48']])['source'];
        $source = DeploySource::query()->where('workspace_id', $workspace->id)->where('token', $token)->first();
        if ($source === null || ! $source->usable()) {
            throw ValidationException::withMessages(['source' => 'That upload has expired. Upload it again.']);
        }

        $last = self::lastDeployOf($machine, $service);
        abort_if($last === null, 404, 'This service wasn’t deployed from code.');

        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        $job = [...$last->command['job'], 'repo' => $source->repoUrl(), 'commit' => $source->commit];

        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }

    /**
     * Go back to an earlier version the machine still keeps, and its data
     * too if asked: the snapshot taken just before the deploy that replaced
     * it. The app's name typed back confirms it.
     */
    #[Audited]
    public function rollback(Request $request, Workspace $workspace, Machine $machine, string $service, AppVersions $versions): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'commit' => ['required', 'string', 'max:64'],
            'with_data' => ['sometimes', 'boolean'],
            'confirm' => ['required', 'string', 'max:255'],
        ]);
        if ($data['confirm'] !== $service) {
            return response()->json(['errors' => ['confirm' => ['Type the app’s name to confirm.']]], 422);
        }

        try {
            $job = $versions->rollback($machine, $request->user(), $service, $data['commit'], (bool) ($data['with_data'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['jobId' => $job->id, 'state' => 'queued'], 202);
    }

    /**
     * An app's environment variables, by name only: values go in and never
     * come back. Read from its last deploy, or its last try if none worked.
     */
    public function variables(Workspace $workspace, Machine $machine, string $service, AppSettings $settings): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_unless(filled(AppSettings::lastDeploy($machine, $service)?->command['job']['repo'] ?? null), 404, 'This service wasn’t deployed from code.');

        return response()->json($settings->readApp($machine, $service));
    }

    /**
     * Change some of an app's environment variables, which deploys it again
     * with them; the running version keeps serving until the new one is up.
     */
    #[Audited]
    public function updateVariables(Request $request, Workspace $workspace, Machine $machine, string $service, AppSettings $settings): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_unless(filled(AppSettings::lastDeploy($machine, $service)?->command['job']['repo'] ?? null), 404, 'This service wasn’t deployed from code.');
        $data = $request->validate([
            'set' => ['array', 'max:50'],
            'set.*' => ['nullable', 'string', 'max:10000'],
            'remove' => ['array', 'max:50'],
            'remove.*' => ['string', 'max:128'],
        ]);

        $set = array_map(fn ($value) => (string) $value, (array) ($data['set'] ?? []));
        $changed = $settings->writeApp($machine, $service, $set, array_values(array_map('strval', (array) ($data['remove'] ?? []))), $request->user());
        if ($changed === []) {
            AuditContext::nothingChanged();
        } else {
            Activity::record($workspace, 'app.variables', $service, $machine, meta: ['names' => $changed]);
        }

        return response()->json(['changed' => $changed, 'jobId' => $changed === [] ? null : AppSettings::lastDeploy($machine, $service)?->id]);
    }

    /**
     * Whether the app's deploys snapshot its data first, and which database
     * goes with it: "auto" (the one its settings point at), "none", or one
     * of the machine's databases.
     */
    #[Audited]
    public function settings(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(self::lastDeployOf($machine, $service) === null, 404, 'This service wasn’t deployed from code.');

        $databases = array_map(fn (array $s) => ServiceLabels::identity($s), AppDatabase::candidates($machine));
        $data = $request->validate([
            'snapshot_before' => ['sometimes', 'boolean'],
            'database' => ['sometimes', 'string', Rule::in(['auto', 'none', ...$databases])],
        ], ['database.in' => 'That database isn’t on this machine.']);

        $settings = $machine->app_settings ?? [];
        $before = $settings[$service] ?? [];
        $settings[$service] = [...$before, ...array_intersect_key($data, array_flip(['snapshot_before', 'database']))];
        $machine->forceFill(['app_settings' => $settings])->save();

        $changed = array_filter($settings[$service], fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            Activity::record($workspace, 'app.settings', $service, $machine, meta: $changed);
        } else {
            AuditContext::nothingChanged();
        }

        return response()->json(['snapshotBefore' => $machine->snapshotsBeforeDeploy($service), 'database' => AppDatabase::setting($machine, $service)]);
    }

    /**
     * The app's database choices for its page: what's set, what that comes
     * to now, and the machine's databases.
     *
     * @return array{setting: string, current: array{name: string, display: string}|null, options: list<array{value: string, label: string}>}
     */
    public static function databaseProps(Machine $machine, DaemonJob $deploy): array
    {
        $current = AppDatabase::for($machine, (string) $deploy->service, (array) ($deploy->command['job']['env'] ?? []));

        return [
            'setting' => AppDatabase::setting($machine, (string) $deploy->service),
            'current' => $current === null ? null : ['name' => ltrim((string) $current['name'], '/'), 'display' => ServiceLabels::display($machine, $current)],
            'options' => array_map(fn (array $s) => ['value' => ServiceLabels::identity($s), 'label' => ServiceLabels::display($machine, $s)], AppDatabase::candidates($machine)),
        ];
    }

    /**
     * Move an app to a new domain. Settings that mention the old domain
     * (a PUBLIC_URL, say) can be moved with it. Uploaded code gets a new
     * commit with the same files so the daemon starts a fresh release
     * beside the live one; the build is cached, so it's quick.
     */
    #[Audited]
    public function domain(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue, DeploySourcePacker $packer): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);

        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => preg_replace(['#^[a-z]+://#i', '#[/?\#].*$#', '#:\d+$#'], '', strtolower(trim($domain)))]);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'move_settings' => ['sometimes', 'boolean'],
        ], ['domain.regex' => 'Enter a domain like app.example.com.']);

        $last = self::lastDeployOf($machine, $service);
        abort_if($last === null, 404, 'This service wasn’t deployed from code.');

        $job = $last->command['job'];
        $old = $job['domains'] ?? [];
        // Left out, an app keeps whether it serves every subdomain.
        $wildcard = $request->has('wildcard') ? $request->boolean('wildcard') : DeployRequest::isWildcard($old);
        $domains = DeployRequest::domains($data['domain'], $wildcard);
        if ($old === $domains) {
            throw ValidationException::withMessages(['domain' => 'It’s already on that domain.']);
        }
        DeployRequest::assertWildcard($machine, $data['domain'], $wildcard);

        // An app built from Git deploys its live commit again, which replaces
        // its container in place; uploaded code is packed under a new commit.
        $fromGit = filled($job['repo'] ?? null) && ! str_contains((string) $job['repo'], '/api/deploy-sources/');
        $source = $fromGit ? null : DeploySource::fromRepoUrl($job['repo'] ?? null);
        if (! $fromGit && ($source === null || $source->workspace_id !== $workspace->id || ! $source->usable())) {
            throw ValidationException::withMessages(['domain' => 'The uploaded code for this app has been cleared. Upload it again as a new version, then change the domain.']);
        }

        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        $oldPlain = DeployRequest::plainDomains($old);
        if ($request->boolean('move_settings') && $oldPlain !== [] && $oldPlain !== [$data['domain']]) {
            $swap = fn (string $value) => str_replace($oldPlain, $data['domain'], $value);
            $job['env'] = array_map(fn ($value) => is_string($value) ? $swap($value) : $value, $job['env'] ?? []);
            $job['files'] = array_map(fn ($value) => is_string($value) ? $swap($value) : $value, $job['files'] ?? []);
        }

        $job['domains'] = $domains;
        if ($source !== null) {
            $job['commit'] = $packer->recommit($source, 'Moved to '.$data['domain']);
        }

        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }

    /**
     * Names of the settings that mention the app's current domain, so the
     * page can offer to move them too. Names only: the values are secret.
     *
     * @return list<string>
     */
    public static function mentions(DaemonJob $deploy): array
    {
        $domains = DeployRequest::plainDomains($deploy->command['job']['domains'] ?? []);
        if ($domains === []) {
            return [];
        }

        $mentions = fn (array $values) => array_keys(array_filter($values, fn ($value) => is_string($value) && str_replace($domains, '', $value) !== $value));

        $names = $mentions($deploy->command['job']['env'] ?? []);
        foreach ($mentions($deploy->command['job']['files'] ?? []) as $file) {
            // The build-time env file: name the settings inside it.
            foreach (preg_split('/\R/', (string) $deploy->command['job']['files'][$file]) ?: [] as $line) {
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m) && str_replace($domains, '', $line) !== $line) {
                    $names[] = $m[1];
                }
            }
        }

        return array_values(array_unique($names));
    }
}
