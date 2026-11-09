<?php

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Logs\LogStore;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Log storage (Business): a Postgres the workspace owns, where
 * its log lines go instead of ours. Saved, tested, then turned on; while
 * it's on, lines go there and are read from there only.
 */
class WorkspaceLogStoreController extends Controller
{
    /** The connection fields the form sends, in the order errors show. */
    private const FIELDS = ['host', 'port', 'database', 'username', 'password'];

    public function show(Request $request, Workspace $workspace): Response
    {
        $store = self::store($workspace);

        return Inertia::render('workspace/settings/LogStorage', [
            'workspace' => $workspace->toPageProps(),
            'canManage' => Gate::allows('manageSettings', $workspace),
            'planAllows' => $workspace->hasOwnLogStorage(),
            // Where our connections come from, for their firewall.
            'egressIp' => config('serveros.egress_ip'),
            'retentionDays' => ($workspace->effectivePlan() ?? Plan::Business)->logRetentionDays(),
            // Never the password: only whether one is saved.
            'store' => $store === null ? null : [
                'host' => $store->host,
                'port' => $store->port,
                'database' => $store->database,
                'username' => $store->username,
                'hasPassword' => $store->password !== '',
                'enabled' => $store->enabled,
                'prune' => $store->prune,
                'status' => $store->status,
                'lastError' => $store->last_error,
                'lastOkAt' => $store->last_ok_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Save the connection. A blank password keeps the saved one. Changing
     * where an enabled store points tests the new details first, and
     * creates our tables there, so lines never go somewhere broken.
     */
    #[Audited]
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace);
        $data = $this->connection($request, $store);
        $prune = $request->validate(['prune' => ['required', 'boolean']])['prune'];

        $store ??= new WorkspaceLogStore(['workspace_id' => $workspace->id, 'driver' => 'pgsql', 'enabled' => false]);
        $store->fill([...$data, 'prune' => (bool) $prune]);

        $moved = $store->isDirty(self::FIELDS);
        if ($store->exists && $store->enabled && $moved) {
            if (($problem = LogStore::test($store)) !== null) {
                throw ValidationException::withMessages(['connection' => $problem]);
            }
            LogStore::install($store);
            $store->fill(['status' => 'ok', 'last_error' => null, 'last_ok_at' => now()]);
        }

        if (! $store->isDirty()) {
            AuditContext::nothingChanged();

            return back();
        }

        $created = ! $store->exists;
        $store->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_update', $store->host, meta: array_filter([
            'added' => $created ?: null,
            'connection' => ! $created && $moved ?: null,
            'prune' => $store->wasChanged('prune') || $created ? $store->prune : null,
        ], fn ($v) => $v !== null));

        return back();
    }

    /**
     * Try the details in the form (or the saved ones, when it sends none)
     * without saving or turning anything on.
     */
    #[Audited]
    public function test(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace);

        if ($request->filled('host')) {
            $trial = ($store?->replicate() ?? new WorkspaceLogStore(['driver' => 'pgsql']))->fill($this->connection($request, $store));
            $trial->workspace_id = $workspace->id;
        } elseif ($store !== null) {
            $trial = $store;
        } else {
            throw ValidationException::withMessages(['host' => __('Enter the database’s host first.')]);
        }

        $problem = LogStore::test($trial);
        // Testing the saved store says how it is now.
        if ($trial === $store) {
            $problem === null ? LogStore::markOk($workspace) : $store->forceFill(['status' => 'unreachable', 'last_error' => $problem])->save();
        }
        AuditContext::nothingChanged();

        if ($problem !== null) {
            throw ValidationException::withMessages(['connection' => $problem]);
        }

        return back();
    }

    /** Test, create our tables, then send new lines there. */
    #[Audited]
    public function enable(Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace) ?? throw ValidationException::withMessages(['host' => __('Save the connection first.')]);

        if ($store->enabled) {
            AuditContext::nothingChanged();

            return back();
        }
        if (($problem = LogStore::test($store)) !== null) {
            $store->forceFill(['status' => 'unreachable', 'last_error' => $problem])->save();
            throw ValidationException::withMessages(['connection' => $problem]);
        }

        LogStore::install($store);
        $store->forceFill(['enabled' => true, 'status' => 'ok', 'last_error' => null, 'last_ok_at' => now()])->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_enable', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New log lines now go to your database.')]);

        return back();
    }

    /**
     * New lines come back to ours. Lines already in their database stay
     * there, out of sight until it's turned on again.
     */
    #[Audited]
    public function disable(Request $request, Workspace $workspace): RedirectResponse
    {
        // Allowed on any plan, so a workspace that left Business can stop.
        Gate::authorize('manageSettings', $workspace);
        $store = self::store($workspace);

        if ($store === null || ! $store->enabled) {
            AuditContext::nothingChanged();

            return back();
        }

        $store->forceFill(['enabled' => false])->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_disable', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New log lines go to ServerOS again.')]);

        return back();
    }

    /** Forget the connection. Only once it's off. */
    #[Audited]
    public function destroy(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $store = self::store($workspace);

        if ($store === null) {
            AuditContext::nothingChanged();

            return back();
        }
        if ($store->enabled) {
            throw ValidationException::withMessages(['connection' => __('Turn it off before removing it.')]);
        }

        $store->delete();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_remove', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed the connection. Lines already in that database are still there.')]);

        return back();
    }

    private static function store(Workspace $workspace): ?WorkspaceLogStore
    {
        return WorkspaceLogStore::query()->where('workspace_id', $workspace->id)->first();
    }

    private function authorizeChange(Workspace $workspace): void
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $workspace->hasOwnLogStorage()) {
            throw ValidationException::withMessages(['host' => __('Logs in your own database come with Business.')]);
        }
    }

    /**
     * The connection details from the form, checked. A blank password
     * keeps the saved one; the host has to be on the public internet.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function connection(Request $request, ?WorkspaceLogStore $store): array
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:63'],
            'username' => ['required', 'string', 'max:63'],
            'password' => [$store === null ? 'required' : 'nullable', 'string', 'max:200'],
        ], [
            'host.regex' => __('Use the database’s host name or IP address, like db.example.com.'),
        ]);

        $host = strtolower(trim($data['host'], " []\t\n"));
        // Not the panel's own network: our database, cloud metadata and so on.
        if ($host !== ($store?->host) && app(HostResolver::class)->resolve($host) === []) {
            throw ValidationException::withMessages(['host' => __('That host doesn’t resolve to a public address.')]);
        }

        return [
            'host' => $host,
            'port' => (int) $data['port'],
            'database' => trim($data['database']),
            'username' => trim($data['username']),
            'password' => filled($data['password'] ?? null) ? (string) $data['password'] : (string) $store?->password,
        ];
    }
}
