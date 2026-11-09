<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\ServiceGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every service on every machine, as the Services page lists them: from
 * each machine's latest scan, retired versions of deployed apps left out,
 * with live usage, filtered and sorted the same way.
 */
final class ListServices extends Tool
{
    private const SORTS = ['cpu', 'memory', 'uptime', 'name'];

    public function name(): string
    {
        return 'list_services';
    }

    public function title(): string
    {
        return 'List services';
    }

    public function description(): string
    {
        return 'Lists the services ServerOS found on the workspace’s machines (Docker containers, systemd units and the like), from each machine’s latest scan, like the Services page. Old versions of apps ServerOS deployed are left out: each app shows once, as its live version (app is its name). '
            .'Each service has its key (use it, with the machine id, for get_service and the other service tools), name (the name given in ServerOS, else the app’s or what was found), foundAs (the name on the machine), status (running, stopped, exited, failed, restarting…), kind, manager, managed (adopted by ServerOS, so it can be started, stopped and so on), container, image, ports, icon, group (its service group, if any), stats (latest usage: cpu %, memUsed bytes, restarts, startedAt unix time) and url. '
            .'Filters: machine; status (running, stopped (anything not running and not a problem) or problems (failed or restarting), as the page’s tabs, or an exact status); kind; managed; container; group (a service group’s id or name); query (matches names, key, kind, manager, image, ports and machine name). Sorted by CPU use by default, like the page; sort by memory, uptime or name instead. '
            .'Services someone hid from the panel’s lists are left out, as on the page; hidden says how many matched but were hidden, and hidden: true lists only those (unhide_service shows one again). '
            .'counts are by status over everything matching except the status filter. Machines that are offline or not scanned yet are listed under stale: their services are as last seen.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only services on this machine: its id or name.'),
            'status' => Schema::string('running, stopped or problems (as the page’s tabs), or an exact status like exited.', maxLength: 40),
            'kind' => Schema::string('Only this kind of service, like database, cache or web_server (as list_services shows).', maxLength: 60),
            'managed' => Schema::boolean('true: only services ServerOS controls (adopted); false: only those it just found.'),
            'container' => Schema::boolean('true: only containers; false: only other services (systemd units and the like).'),
            'hidden' => Schema::boolean('true: only the services hidden from the panel’s lists. Left out: only the ones shown.'),
            'group' => Schema::string('Only services in this service group: its id or name (list_service_groups shows them).', maxLength: 120),
            'query' => Schema::string('Search text, matched against names, key, kind, manager, image, ports and the machine’s name.', maxLength: 200),
            'sort' => Schema::string('cpu (default), memory, uptime or name.', self::SORTS),
            'limit' => Schema::integer('How many to return (1–200, default 50).', 1, 200),
            'offset' => Schema::integer('How many to skip, to page through (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['sometimes', ServiceWork::reference()],
            'status' => ['sometimes', 'string', 'max:40'],
            'kind' => ['sometimes', 'string', 'max:60'],
            'managed' => ['sometimes', 'boolean'],
            'container' => ['sometimes', 'boolean'],
            'hidden' => ['sometimes', 'boolean'],
            'group' => ['sometimes', ServiceWork::reference()],
            'query' => ['sometimes', 'string', 'max:200'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', self::SORTS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $machines = isset($data['machine'])
            ? collect([$context->machine($data['machine'])])
            : $context->workspace->machines()->orderBy('name')->get();

        $groups = $machines->mapWithKeys(fn (Machine $m) => [$m->id => ServiceGroups::of($m)]);
        $wantedGroups = isset($data['group']) ? $this->groups($machines, $groups, (string) $data['group']) : null;

        // Every service with where it runs, described like the service page.
        $all = $machines->flatMap(fn (Machine $machine) => $context->services($machine)->map(function (array $service) use ($context, $machine, $groups) {
            $service = $context->describeService($machine, $service);

            return ['machine' => $machine, 'service' => $service, 'group' => ServiceGroups::containing($groups[$machine->id], $service)];
        }));

        $needle = mb_strtolower(trim((string) ($data['query'] ?? '')));
        $matching = $all->filter(function (array $row) use ($data, $needle, $wantedGroups) {
            $service = $row['service'];
            if (isset($data['kind']) && mb_strtolower((string) ($service['kind'] ?? '')) !== mb_strtolower(trim($data['kind']))) {
                return false;
            }
            if (isset($data['managed']) && $service['managed'] !== (bool) $data['managed']) {
                return false;
            }
            if (isset($data['container']) && $service['container'] !== (bool) $data['container']) {
                return false;
            }
            if ($wantedGroups !== null && ($row['group'] === null || ! in_array($row['group']->id, $wantedGroups, true))) {
                return false;
            }
            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $service['display'], $service['name'] ?? '', $service['label'] ?? '', $service['key'], $service['kind'] ?? '', $service['manager'] ?? '',
                    $service['details']['image'] ?? '', implode(' ', array_map('strval', $service['ports'] ?? [])), $row['machine']->name,
                ]));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        });

        $wantHidden = (bool) ($data['hidden'] ?? false);
        $hidden = $matching->filter(fn (array $row) => (bool) ($row['service']['hidden'] ?? false))->count();
        $matching = $matching->filter(fn (array $row) => (bool) ($row['service']['hidden'] ?? false) === $wantHidden);

        $counts = ['running' => 0, 'stopped' => 0, 'problems' => 0];
        foreach ($matching as $row) {
            $counts[self::statusGroup($row['service'])]++;
        }

        if (isset($data['status']) && trim($data['status']) !== '') {
            $status = mb_strtolower(trim($data['status']));
            $matching = $matching->filter(fn (array $row) => in_array($status, ['running', 'stopped', 'problems'], true)
                ? self::statusGroup($row['service']) === $status
                : mb_strtolower((string) ($row['service']['status'] ?? '')) === $status);
        }

        $sorted = $this->sort($matching, $data['sort'] ?? 'cpu');
        $offset = (int) ($data['offset'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $page = $sorted->slice($offset, $limit)->values();

        return [
            'total' => $sorted->count(),
            'offset' => $offset,
            'limit' => $limit,
            'counts' => $counts,
            'hidden' => $hidden,
            'services' => $page->map(fn (array $row) => ServiceView::row($context, $row['machine'], $row['service'], $row['group']))->all(),
            'stale' => $machines->filter(fn (Machine $m) => $m->inventory === null || Present::connection($m) !== 'online')->map(fn (Machine $m) => [
                'machine' => ['id' => $m->id, 'name' => $m->name],
                'status' => Present::connection($m),
                'lastScannedAt' => is_int($m->inventory['scanned_at'] ?? null) ? Carbon::createFromTimestamp($m->inventory['scanned_at'])->toIso8601String() : null,
            ])->values()->all(),
            'url' => $context->url('/services'),
        ];
    }

    /**
     * As the page's tabs group them.
     *
     * @param  array<string, mixed>  $service
     */
    public static function statusGroup(array $service): string
    {
        return match ($service['status'] ?? null) {
            'running' => 'running',
            'failed', 'restarting' => 'problems',
            default => 'stopped',
        };
    }

    /**
     * The ids of the groups the filter names, on the machines being listed.
     *
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, Collection<int, ServiceGroup>>  $groups
     * @return list<int>
     */
    private function groups(Collection $machines, Collection $groups, string $reference): array
    {
        if ($machines->count() === 1) {
            return [ServiceGroups::find($machines->first(), $reference)->id];
        }

        $reference = trim($reference);
        $ids = array_values($groups->flatMap(fn (Collection $list) => $list)
            ->filter(fn (ServiceGroup $g) => (ctype_digit($reference) && $g->id === (int) $reference) || mb_strtolower($g->name) === mb_strtolower($reference))
            ->map(fn (ServiceGroup $g) => $g->id)->all());
        if ($ids === []) {
            throw new ToolError("No service group “{$reference}” on any machine. list_service_groups shows a machine’s groups.");
        }

        return $ids;
    }

    /**
     * Highest first, as the page sorts: CPU, memory, longest running; or
     * by name.
     *
     * @param  Collection<int, array{machine: Machine, service: array<string, mixed>, group: ServiceGroup|null}>  $rows
     * @return Collection<int, array{machine: Machine, service: array<string, mixed>, group: ServiceGroup|null}>
     */
    private function sort(Collection $rows, string $sort): Collection
    {
        if ($sort === 'name') {
            return $rows->sortBy(fn (array $row) => mb_strtolower($row['service']['display']).' '.$row['machine']->name)->values();
        }

        $now = now()->getTimestamp();
        $value = function (array $row) use ($sort, $now): float {
            $stat = $row['machine']->service_stats[$row['service']['key']] ?? null;

            return (float) match ($sort) {
                'memory' => $stat['memUsed'] ?? -1,
                'uptime' => isset($stat['startedAt']) && $stat['startedAt'] ? $now - $stat['startedAt'] : -1,
                default => $stat['cpu'] ?? -1,
            };
        };

        return $rows->sortByDesc($value)->values();
    }
}
