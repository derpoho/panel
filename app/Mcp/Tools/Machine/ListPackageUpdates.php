<?php

namespace App\Mcp\Tools\Machine;

use App\Http\Controllers\UpdatesController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;

final class ListPackageUpdates extends Tool
{
    public function name(): string
    {
        return 'list_package_updates';
    }

    public function title(): string
    {
        return 'List OS updates across machines';
    }

    public function description(): string
    {
        return 'Lists the operating system package updates waiting across the workspace’s machines, one entry per package, as each machine’s last check found it (machines are checked about once a day; check_updates asks one now). Each package has whether it’s a security update, the machines it waits on, the versions offered (each with the OS release and the machines offering it: exact versions in create_package_rollout must be one of these), the versions installed now, and the machines holding it at its version. '
            .'Also a summary (updates, packages, security, machines with updates, machines never checked, machines needing a restart, the oldest check) and each machine’s counts. Filter with security (only security updates), machine (one machine’s id or name) and query (part of a package name). Up to limit packages (default 100) from offset.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'security' => Schema::boolean('Only security updates.'),
            'machine' => Schema::machine('Only updates waiting on this machine: its id or name.'),
            'query' => Schema::string('Only packages whose name contains this.', maxLength: 120),
            'limit' => Schema::integer('How many packages to return (1–500, default 100).', 1, 500),
            'offset' => Schema::integer('How many to skip, for the next page (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'security' => ['sometimes', 'boolean'],
            'machine' => ['sometimes', 'nullable', ServerWork::reference()],
            'query' => ['sometimes', 'nullable', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $machines = $context->workspace->machines()->whereNotNull('uid')->orderBy('name')->get();
        if (filled($data['machine'] ?? null)) {
            $only = $context->machine($data['machine']);
            $machines = $machines->where('id', $only->id)->values();
        }
        $rows = MachinePackageUpdate::query()->whereIn('machine_id', $machines->pluck('id'))->orderBy('name')
            ->get(['machine_id', 'name', 'installed', 'candidate', 'security']);

        $names = $machines->pluck('name', 'id');
        $search = mb_strtolower(trim((string) ($data['query'] ?? '')));
        $packages = collect(UpdatesController::packages($machines, $rows))
            ->filter(fn (array $p) => ! ($data['security'] ?? false) || $p['security'])
            ->filter(fn (array $p) => $search === '' || str_contains(mb_strtolower($p['name']), $search))
            ->values();

        $offset = (int) ($data['offset'] ?? 0);
        $page = $packages->slice($offset, (int) ($data['limit'] ?? 100))->values();
        $machineRef = fn (int $id) => ['id' => $id, 'name' => $names[$id] ?? null];

        $checked = $machines->whereNotNull('updates_checked_at');

        return [
            'summary' => [
                'updates' => $rows->count(),
                'packages' => $rows->pluck('name')->unique()->count(),
                'security' => $rows->where('security', true)->count(),
                'machinesWithUpdates' => $rows->pluck('machine_id')->unique()->count(),
                'machines' => $machines->count(),
                'neverChecked' => $machines->count() - $checked->count(),
                'rebootRequired' => $machines->filter(fn ($m) => $m->needsReboot())->count(),
                'oldestCheck' => $checked->min('updates_checked_at')?->toIso8601String(),
            ],
            'total' => $packages->count(),
            'count' => $page->count(),
            'offset' => $offset,
            'more' => $offset + $page->count() < $packages->count(),
            'packages' => $page->map(fn (array $p) => [
                'name' => $p['name'],
                'security' => $p['security'],
                'machines' => array_map($machineRef, $p['machineIds']),
                'versions' => array_map(fn (array $v) => [
                    'version' => $v['version'],
                    'osRelease' => $v['release'],
                    'os' => $v['os'],
                    'machines' => array_map($machineRef, $v['machineIds']),
                ], $p['versions']),
                'installed' => $p['installed'],
                'heldOn' => array_map($machineRef, $p['heldOn']),
            ])->all(),
            'machines' => $machines->map(fn (Machine $m) => [
                'id' => $m->id,
                'name' => $m->name,
                ...MachineView::updateSummary($m),
            ])->values()->all(),
            'url' => $context->url('/updates'),
        ];
    }
}
