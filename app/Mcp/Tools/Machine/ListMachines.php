<?php

namespace App\Mcp\Tools\Machine;

use App\Enums\MachineEnvironment;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Support\Machines\MachineMetadata;
use Illuminate\Validation\Rule;

/**
 * The machines page: every machine with how it's doing, filtered the way
 * the page filters them.
 */
final class ListMachines extends Tool
{
    private const STATUSES = ['online', 'offline', 'connecting', 'demo', 'maintenance'];

    private const SORTS = ['name', 'cpu', 'memory', 'disk'];

    public function name(): string
    {
        return 'list_machines';
    }

    public function title(): string
    {
        return 'List machines';
    }

    public function description(): string
    {
        return 'Lists the workspace’s machines (Linux servers running the ServerOS daemon). Each has its id and name (use either in other tools), environment (production, staging, development), status (online; offline: hasn’t reported for a minute; connecting: installed but not reported yet; demo: a sample machine with no daemon), IP, provider, OS, CPU %, memory used, fullest disk %, service count, group, any maintenance window, and its panel URL. '
            .'Filter with environment, status ("maintenance" means in a maintenance window now), group (a group’s id or name, or "none" for machines in no group), provider, region, tier, label ("key=value", or just "key" for any value) and query (part of a name, IP or provider). Sorted by name, or by cpu, memory or disk (busiest first). Up to limit machines (default 50) from offset; "more" says whether there are others. get_machine gives one machine in full.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'environment' => Schema::string('Only machines in this environment.', array_map(fn (MachineEnvironment $e) => $e->value, MachineEnvironment::cases())),
            'status' => Schema::string('Only machines with this status; "maintenance" for those in a maintenance window now.', self::STATUSES),
            'group' => Schema::string('Only machines in this group (its id or name), or "none" for machines in no group.', maxLength: 120),
            'provider' => Schema::string('Only machines at this provider, like Hetzner (any case), as the page’s provider filter.', maxLength: 120),
            'region' => Schema::string('Only machines in this region (any case).', maxLength: 60),
            'tier' => Schema::string('Only machines in this tier (any case).', maxLength: 60),
            'label' => Schema::string('Only machines with this label: "key=value", or "key" for any value.', maxLength: 150),
            'query' => Schema::string('Only machines whose name, IP or provider contains this.', maxLength: 120),
            'sort' => Schema::string('Order: name (default), or cpu, memory or disk, busiest first.', self::SORTS),
            'limit' => Schema::integer('How many to return (1–200, default 50).', 1, 200),
            'offset' => Schema::integer('How many to skip, for the next page (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'environment' => ['sometimes', 'nullable', Rule::enum(MachineEnvironment::class)],
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'group' => ['sometimes', 'nullable', ServerWork::reference()],
            'provider' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'label' => ['sometimes', 'nullable', 'string', 'max:150'],
            'query' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in(self::SORTS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $groups = $context->workspace->machineGroups()->get()->keyBy('id');
        $query = $context->workspace->machines()->orderBy('name');
        if (filled($data['environment'] ?? null)) {
            $query->where('environment', $data['environment']);
        }
        if (filled($data['group'] ?? null)) {
            if (mb_strtolower(trim((string) $data['group'])) === 'none') {
                $query->whereNull('machine_group_id');
            } else {
                $query->where('machine_group_id', MachineGroups::find($context, $data['group'])->id);
            }
        }

        $status = $data['status'] ?? null;
        $provider = mb_strtolower(trim((string) ($data['provider'] ?? '')));
        $search = mb_strtolower(trim((string) ($data['query'] ?? '')));
        $machines = $query->get()
            ->filter(fn (Machine $m) => match ($status) {
                null => true,
                'maintenance' => $m->inMaintenance(),
                default => Present::connection($m) === $status,
            })
            ->filter(fn (Machine $m) => $provider === '' || mb_strtolower(trim((string) $m->provider)) === $provider)
            ->filter(fn (Machine $m) => ! filled($data['region'] ?? null) || MachineMetadata::matches($m, 'region', (string) $data['region']))
            ->filter(fn (Machine $m) => ! filled($data['tier'] ?? null) || MachineMetadata::matches($m, 'tier', (string) $data['tier']))
            ->filter(function (Machine $m) use ($data) {
                if (! filled($data['label'] ?? null)) {
                    return true;
                }
                [$key, $value] = array_pad(explode('=', (string) $data['label'], 2), 2, null);

                return $value === null ? MachineMetadata::value($m, 'label:'.trim($key)) !== null : MachineMetadata::matches($m, 'label:'.trim($key), $value);
            })
            ->filter(fn (Machine $m) => $search === '' || collect([$m->name, $m->ip_address, $m->provider])
                ->contains(fn ($value) => str_contains(mb_strtolower((string) $value), $search)));

        $machines = match ($data['sort'] ?? 'name') {
            'cpu' => $machines->sortByDesc(fn (Machine $m) => $m->cpu_percent ?? -1),
            'memory' => $machines->sortByDesc(fn (Machine $m) => $m->memory_used_mb ?? -1),
            'disk' => $machines->sortByDesc(fn (Machine $m) => $m->disk_percent ?? -1),
            default => $machines->sortBy(fn (Machine $m) => mb_strtolower($m->name), SORT_NATURAL),
        };

        $total = $machines->count();
        $offset = (int) ($data['offset'] ?? 0);
        $page = $machines->slice($offset, (int) ($data['limit'] ?? 50))->values();

        return [
            'total' => $total,
            'count' => $page->count(),
            'offset' => $offset,
            'more' => $offset + $page->count() < $total,
            'machines' => $page->map(fn (Machine $m) => MachineView::row($m, $context, $m->machine_group_id === null ? null : $groups->get($m->machine_group_id)))->all(),
            'url' => $context->url('/machines'),
        ];
    }
}
