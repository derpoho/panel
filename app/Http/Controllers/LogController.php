<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteLogLines;
use App\Models\Activity;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Logs\LogCounts;
use App\Support\Logs\LogDeletion;
use App\Support\Logs\LogQuota;
use App\Support\Logs\LogStore;
use App\Support\StatusPages\StatusReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;

/**
 * Service logs across the workspace's machines: filter by machine, service,
 * level, time and text; follow new lines as they arrive; open any line in
 * the context of the lines around it. Lines are read from wherever the
 * workspace keeps them (LogStore): ours, or its own database, which may
 * not answer; the page still opens then, empty, and says so.
 */
class LogController extends Controller
{
    /** Lines in the first page and each "older" page. */
    public const PAGE = 200;

    /** Most lines one poll for new ones returns. */
    public const FOLLOW_LIMIT = 500;

    /** Lines either side of a line opened in context. */
    public const CONTEXT = 40;

    public const RANGES = ['15m' => 15, '1h' => 60, '6h' => 360, '24h' => 1440, '7d' => 10080];

    public function index(Request $request, Workspace $workspace): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get();
        $filters = $this->filters($request, $machines);
        $connection = LogStore::connection($workspace);
        $external = LogStore::isExternal($workspace);

        // The lines, services and store status are read once, and only when
        // a prop needs them: the chart's refresh every 30 seconds asks for
        // the histogram alone, so it doesn't read every machine's newest
        // lines and logged services only to throw them away.
        $read = null;
        $load = function () use (&$read, $workspace, $connection, $machines, $filters, $external): array {
            if ($read !== null) {
                return $read;
            }
            $store = ['external' => $external, 'status' => 'ok', 'error' => null];

            try {
                $lines = $this->newest($connection, $machines, $filters, self::PAGE + 1);
                $services = $this->services($machines, $connection);
                $shipping = LogEntry::on($connection)->whereIn('machine_id', $machines->modelKeys())->exists();
                if ($store['external']) {
                    $this->answered($workspace);
                }
            } catch (QueryException|PDOException $e) {
                $store = $this->unreachable($workspace, $e);
                $lines = collect();
                $services = $this->services($machines, null);
                // Lines are there, just not reachable: not the "waiting" setup page.
                $shipping = true;
            }

            return $read = ['lines' => $lines, 'services' => $services, 'shipping' => $shipping, 'store' => $store];
        };

        return Inertia::render('workspace/Logs', [
            'workspace' => $workspace->toPageProps(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
            ])->values(),
            'services' => fn () => $load()['services'],
            'filters' => $filters,
            'lines' => fn () => $load()['lines']->take(self::PAGE)->reverse()->map(fn (LogEntry $line) => $line->toPageProps())->values(),
            'hasOlder' => fn () => $load()['lines']->count() > self::PAGE,
            'retentionDays' => $workspace->logRetentionDays(),
            'quota' => LogQuota::today($workspace),
            'canDelete' => Gate::allows('manageMachines', $workspace),
            'shipping' => fn () => $load()['shipping'],
            'logStore' => fn () => $load()['store'],
            // Lines over time for the chart: the picked range, or the last
            // hour. It follows every filter but the text search. Our own
            // store always answers (if it doesn't, that's an outage, not a
            // status), so only a workspace's own store is checked first.
            'histogram' => fn () => $this->histogram($workspace, $connection, $machines, $filters, ! $external || $load()['store']['status'] === 'ok'),
        ]);
    }

    /**
     * Lines for the page as it runs: newer than `after` (following), older
     * than `before` (scrolling back), or either side of `around`.
     */
    public function feed(Request $request, Workspace $workspace): JsonResponse
    {
        // Only their ids: this answers every couple of seconds while the
        // page follows, and each machine's scan and stats are big.
        $machines = $workspace->machines()->get(['machines.id']);
        $cursor = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
            'around' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = isset($cursor['around']) ? null : $this->filters($request, $machines);

        try {
            return $this->lines($workspace, $machines, $cursor, $filters);
        } catch (QueryException|PDOException $e) {
            return $this->unavailable($workspace, $e);
        }
    }

    /**
     * The feed's lines, read from the workspace's store.
     *
     * @param  EloquentCollection<int, Machine>  $machines
     * @param  array<string, mixed>  $cursor
     * @param  array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}|null  $filters
     */
    private function lines(Workspace $workspace, EloquentCollection $machines, array $cursor, ?array $filters): JsonResponse
    {
        $connection = LogStore::connection($workspace);

        if ($filters === null) {
            return $this->around($connection, $machines, (int) $cursor['around']);
        }

        if (isset($cursor['before'])) {
            $lines = $this->newest($connection, $machines, $filters, self::PAGE + 1, before: (int) $cursor['before']);

            return response()->json([
                'lines' => $lines->take(self::PAGE)->reverse()->map(fn (LogEntry $line) => $line->toPageProps())->values(),
                'hasOlder' => $lines->count() > self::PAGE,
            ]);
        }

        // Following: the newest lines past the cursor. If more arrived than
        // one poll returns, skip to the latest rather than fall behind.
        $lines = $this->newest($connection, $machines, $filters, self::FOLLOW_LIMIT + 1, after: (int) ($cursor['after'] ?? 0));

        return response()->json([
            'lines' => $lines->take(self::FOLLOW_LIMIT)->reverse()->map(fn (LogEntry $line) => $line->toPageProps())->values(),
            'skipped' => $lines->count() > self::FOLLOW_LIMIT,
        ]);
    }

    /**
     * How many lines a delete would take: what the filters match, or the
     * picked lines. `upTo` and `since` pin it to now, and the delete sends
     * them back, so lines that arrive while the person decides stay.
     */
    public function deletion(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $machines = $workspace->machines()->get();
        $filters = $this->filters($request, $machines);
        $since = $filters['range'] !== null ? now()->subMinutes(self::RANGES[$filters['range']])->getTimestampMs() : null;
        [$machineIds, $scope] = $this->deletionScope($request, $machines, $filters, $since);
        $connection = LogStore::connection($workspace);

        try {
            $upTo = (int) LogEntry::on($connection)->max('id');
            $count = LogDeletion::count($machineIds, $scope, $upTo, $connection);
        } catch (QueryException|PDOException $e) {
            return $this->unavailable($workspace, $e);
        }

        return response()->json([
            'count' => $count,
            'upTo' => $upTo,
            'since' => $since,
        ]);
    }

    /**
     * Delete what the count covered. Big deletes go to the queue.
     */
    #[Audited]
    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $machines = $workspace->machines()->get();
        $filters = $this->filters($request, $machines);
        $pinned = $request->validate([
            'up_to' => ['required', 'integer', 'min:0'],
            'since' => [Rule::requiredIf($filters['range'] !== null), 'nullable', 'integer', 'min:0'],
        ]);
        [$machineIds, $scope] = $this->deletionScope($request, $machines, $filters, $filters['range'] !== null ? (int) $pinned['since'] : null);
        $upTo = (int) $pinned['up_to'];
        $connection = LogStore::connection($workspace);

        try {
            $count = LogDeletion::count($machineIds, $scope, $upTo, $connection);
            if ($count === 0) {
                AuditContext::nothingChanged();

                return response()->json(['deleted' => 0, 'queued' => false]);
            }

            $queued = $count > LogDeletion::SYNC_LIMIT;
            if ($queued) {
                DeleteLogLines::dispatch($workspace->id, $machineIds, $scope, $upTo);
            } else {
                LogDeletion::run($machineIds, $scope, $upTo, $connection);
            }
        } catch (QueryException|PDOException $e) {
            AuditContext::nothingChanged();

            return $this->unavailable($workspace, $e);
        }

        // What was deleted, never the lines themselves (nor the search text,
        // which may be the very thing being removed).
        $machine = count($machineIds) === 1 ? $machines->firstWhere('id', $machineIds[0]) : null;
        Activity::record($workspace, 'service.logs_delete', $machine?->name, $machine instanceof Machine ? $machine : null, meta: array_filter([
            'count' => $count,
            'selected' => $scope['ids'] !== null ? count($scope['ids']) : null,
            'service' => $scope['ids'] === null ? $filters['service'] : null,
            'level' => $scope['ids'] === null ? $filters['level'] : null,
            'range' => $scope['ids'] === null ? $filters['range'] : null,
            'search' => $scope['ids'] === null && $filters['q'] !== null ? true : null,
            'queued' => $queued ?: null,
        ], fn ($value) => $value !== null));

        return response()->json(['deleted' => $count, 'queued' => $queued]);
    }

    /**
     * The machines and lines a delete covers. Filters on their own must
     * narrow it by time or service, so nobody wipes every line by accident.
     *
     * @param  EloquentCollection<int, Machine>  $machines
     * @param  array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}  $filters
     * @return array{0: list<int>, 1: array{service: string|null, level: string|null, q: string|null, since: int|null, ids: list<int>|null}}
     */
    private function deletionScope(Request $request, EloquentCollection $machines, array $filters, ?int $since): array
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:'.self::FOLLOW_LIMIT],
            'ids.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'] ?? [])));

        if ($ids === [] && $filters['range'] === null && $filters['service'] === null) {
            throw ValidationException::withMessages(['scope' => 'Pick a time range or a service first, so a delete can’t take every line at once.']);
        }

        $machineIds = $filters['machine'] !== null && $ids === []
            ? [$filters['machine']]
            : array_values($machines->map(fn (Machine $machine) => $machine->id)->all());

        return [$machineIds, [
            'service' => $filters['service'],
            'level' => $filters['level'],
            'q' => $filters['q'],
            'since' => $since,
            'ids' => $ids === [] ? null : $ids,
        ]];
    }

    /**
     * @param  EloquentCollection<int, Machine>  $machines
     */
    private function around(string $connection, EloquentCollection $machines, int $id): JsonResponse
    {
        $line = LogEntry::on($connection)->whereIn('machine_id', $machines->modelKeys())->find($id);
        abort_if($line === null, 404);

        $same = fn () => LogEntry::on($connection)
            ->where('machine_id', $line->machine_id)
            ->where('service', $line->service);

        $before = $same()->where('id', '<', $line->id)->orderByDesc('id')->limit(self::CONTEXT)->get()->reverse();
        $after = $same()->where('id', '>', $line->id)->orderBy('id')->limit(self::CONTEXT)->get();

        return response()->json([
            'lines' => $before->push($line)->concat($after)->map(fn (LogEntry $l) => $l->toPageProps())->values(),
        ]);
    }

    /**
     * @param  EloquentCollection<int, Machine>  $machines
     * @return array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}
     */
    private function filters(Request $request, EloquentCollection $machines): array
    {
        $data = $request->validate([
            'machine' => ['nullable', 'integer', Rule::in($machines->modelKeys())],
            'service' => ['nullable', 'string', 'max:200'],
            'level' => ['nullable', Rule::in(['error', 'warn'])],
            'q' => ['nullable', 'string', 'max:200'],
            'range' => ['nullable', Rule::in(array_keys(self::RANGES))],
        ]);

        return [
            'machine' => isset($data['machine']) ? (int) $data['machine'] : null,
            'service' => $data['service'] ?? null,
            'level' => $data['level'] ?? null,
            'q' => isset($data['q']) && trim($data['q']) !== '' ? trim($data['q']) : null,
            'range' => $data['range'] ?? null,
        ];
    }

    /**
     * The newest matching lines, newest first. One query per machine, each
     * walking the (machine_id, id) index and stopping at the limit, then
     * merged: one query with `machine_id in (...) order by id desc` can't
     * use that index for the order and read far too much on a big table.
     *
     * @param  EloquentCollection<int, Machine>  $machines
     * @param  array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}  $filters
     * @return Collection<int, LogEntry>
     */
    private function newest(string $connection, EloquentCollection $machines, array $filters, int $limit, ?int $before = null, ?int $after = null): Collection
    {
        $ids = $filters['machine'] !== null ? [$filters['machine']] : $machines->map(fn (Machine $machine) => $machine->id)->all();

        return collect(array_values($ids))
            ->flatMap(fn (int $id) => $this->query($connection, $id, $filters)
                ->when($before !== null, fn (Builder $q) => $q->where('id', '<', $before))
                ->when($after !== null, fn (Builder $q) => $q->where('id', '>', $after))
                ->orderByDesc('id')
                ->limit($limit)
                ->get())
            ->sortByDesc('id')
            ->take($limit)
            ->values();
    }

    /**
     * @param  array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}  $filters
     * @return Builder<LogEntry>
     */
    private function query(string $connection, int $machineId, array $filters): Builder
    {
        return LogEntry::on($connection)
            ->where('machine_id', $machineId)
            ->when($filters['service'], fn (Builder $q, string $service) => $q->where('service', $service))
            ->when($filters['level'], fn (Builder $q, string $level) => $q->whereIn('level', $level === 'error' ? ['error'] : ['error', 'warn']))
            ->when($filters['range'], fn (Builder $q, string $range) => $q->where('logged_at', '>=', now()->subMinutes(self::RANGES[$range])->getTimestampMs()))
            ->when($filters['q'], fn (Builder $q, string $text) => $q->whereLike('message', '%'.addcslashes($text, '%_\\').'%'));
    }

    /**
     * The distinct services a machine has logged under. `select distinct`
     * reads every line; this hops along the (machine_id, service, id) index
     * instead, one lookup per service: each step asks for the smallest
     * service name after the last one (a loose index scan). Capped at 500.
     *
     * @return list<string>
     */
    private function loggedServices(string $connection, int $machineId): array
    {
        $rows = DB::connection($connection)->select(
            'with recursive found(service, depth) as (
                select min(service), 1 from log_entries where machine_id = ?
                union all
                select (select min(service) from log_entries where machine_id = ? and service > found.service), depth + 1
                from found where found.service is not null and depth < 500
            )
            select service from found where service is not null',
            [$machineId, $machineId],
        );

        return array_values(array_map(fn (object $row) => (string) (get_object_vars($row)['service'] ?? ''), $rows));
    }

    /**
     * Every service the filter can offer: what the machines' scans list,
     * plus anything that has logged since (a service removed later still
     * has its lines). Without a connection (the store isn't answering),
     * only what the scans list.
     *
     * @param  EloquentCollection<int, Machine>  $machines
     * @return list<array{machineId: int, name: string, label: string}>
     */
    private function services(EloquentCollection $machines, ?string $connection): array
    {
        $services = [];

        foreach ($machines as $machine) {
            foreach ($machine->inventory['services'] ?? [] as $service) {
                if (is_array($service) && is_string($service['name'] ?? null) && preg_match('/^(docker|systemd):/', (string) ($service['key'] ?? '')) === 1) {
                    $services["{$machine->id}|{$service['name']}"] = [$machine->id, $service['name']];
                }
            }
        }

        foreach ($connection === null ? [] : $machines as $machine) {
            foreach ($this->loggedServices($connection, $machine->id) as $service) {
                $services["{$machine->id}|{$service}"] = [$machine->id, $service];
            }
        }

        $list = array_map(fn (array $s) => [
            'machineId' => $s[0],
            'name' => $s[1],
            'label' => StatusReport::friendlyName($s[1]),
        ], array_values($services));

        usort($list, fn (array $a, array $b) => [$a['machineId'], $a['label']] <=> [$b['machineId'], $b['label']]);

        return $list;
    }

    /**
     * The chart's buckets, from the store's per-minute counts; all empty
     * when the store isn't answering.
     *
     * @param  EloquentCollection<int, Machine>  $machines
     * @param  array{machine: int|null, service: string|null, level: string|null, q: string|null, range: string|null}  $filters
     * @return array{bucketMinutes: int, buckets: list<array{at: int, lines: int, warn: int, error: int}>}
     */
    private function histogram(Workspace $workspace, string $connection, EloquentCollection $machines, array $filters, bool $reachable): array
    {
        $minutes = self::RANGES[$filters['range'] ?? '1h'];
        $ids = $filters['machine'] !== null ? [$filters['machine']] : array_values($machines->map(fn (Machine $machine) => $machine->id)->all());

        if ($reachable) {
            try {
                return LogCounts::histogram($ids, $minutes, $filters['service'], $filters['level'], $connection);
            } catch (QueryException|PDOException $e) {
                $this->unreachable($workspace, $e);
            }
        }

        return LogCounts::histogram([], $minutes);
    }

    /** The workspace's own store answered: clear an old "unreachable". */
    private function answered(Workspace $workspace): void
    {
        if (WorkspaceLogStore::query()->where('workspace_id', $workspace->id)->where('status', '!=', 'ok')->exists()) {
            LogStore::markOk($workspace);
        }
    }

    /**
     * Note that the workspace's store didn't answer, and say so to the page.
     *
     * @return array{external: bool, status: string, error: string|null}
     */
    private function unreachable(Workspace $workspace, QueryException|PDOException $e): array
    {
        // Our own database failing is an outage, not a customer's store.
        if (! LogStore::isExternal($workspace)) {
            throw $e;
        }
        LogStore::markUnreachable($workspace, $e);

        return ['external' => true, 'status' => 'unreachable', 'error' => LogStore::reason($e)];
    }

    /** The store didn't answer a request the page made as it runs: say so, in words. */
    private function unavailable(Workspace $workspace, QueryException|PDOException $e): JsonResponse
    {
        $this->unreachable($workspace, $e);

        return response()->json(['message' => 'Can’t reach your log database right now. Try again in a minute.'], 503);
    }
}
