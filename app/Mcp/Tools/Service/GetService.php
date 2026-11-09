<?php

namespace App\Mcp\Tools\Service;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\DaemonJob;

/**
 * One service as its page shows it: what it is, its usage now and over a
 * window, its recent jobs, what ServerOS deployed into it, and what can be
 * done with it from here.
 */
final class GetService extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'get_service';
    }

    public function title(): string
    {
        return 'Get a service';
    }

    public function description(): string
    {
        return 'Returns one service on a machine as its page in ServerOS shows it: the service (key, name, status, managed, container, image, ports, group, latest stats), details (compose project, restart policy, version, user, working directory, executable, config files, any warning), links (where its ports open, on a local test node), usage over a window (range: 15m, 1h (default), 6h or 24h; averages, peaks, latest, network rates and a 12-point trend), its recent jobs (start, stop, restart, logs, commands, adopt, remove, and deploys of an app), and app (for an app ServerOS deployed from code: repo or upload, commit, domains, when). '
            .'controls says what can be done now: whether it is adopted, whether the machine can take work, and why not. Refer to the service by key, name, the label given in ServerOS, or a deployed app’s name.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'range' => Schema::string('The usage window: 15m, 1h (default), 6h or 24h.', array_keys(ServiceView::RANGES)),
            'jobs' => Schema::integer('How many recent jobs to include (0–30, default 10).', 0, 30),
        ], ['machine', 'service']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'range' => ['sometimes', 'string', 'in:'.implode(',', array_keys(ServiceView::RANGES))],
            'jobs' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);
        $key = (string) $service['key'];
        $group = ServiceGroups::containing(ServiceGroups::of($machine), $service);

        $jobs = DaemonJob::query()
            ->with(['machine', 'user'])
            ->where('machine_id', $machine->id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('service', $key)->whereIn('kind', ServiceWork::SERVICE_JOBS))
                // An app's deploys are filed under its name.
                ->when($service['app'] ?? null, fn ($q, $app) => $q->orWhere(fn ($q) => $q->where('service', $app)->where('kind', 'deploy'))))
            ->latest()
            ->limit((int) ($data['jobs'] ?? 10))
            ->get();

        $unavailable = $this->work->unavailable($machine);
        $canManage = $context->can(WorkspacePermission::ManageMachines);

        return [
            'service' => ServiceView::row($context, $machine, $service, $group),
            'machine' => ['id' => $machine->id, 'name' => $machine->name, 'status' => Present::connection($machine)],
            'details' => ServiceView::details($service),
            'links' => collect(is_array($service['ports'] ?? null) ? $service['ports'] : [])->map(fn ($port) => $machine->localAddress((int) $port))->filter()->values()->all(),
            'usage' => ServiceView::usage($machine, $key, $data['range'] ?? '1h'),
            'jobs' => $jobs->map(fn (DaemonJob $job) => ServiceView::job($context, $job, 5))->all(),
            'app' => ServiceView::app($machine, $service),
            'controls' => [
                'managed' => $service['managed'],
                'canControl' => $canManage && $service['managed'] && $unavailable === null,
                'canRunCommands' => $canManage && $service['managed'] && $service['container'] && $unavailable === null,
                'unavailable' => $unavailable,
                'note' => match (true) {
                    ! $canManage => 'Your role can look but not act on services.',
                    ! $service['managed'] => 'ServerOS found this service but doesn’t control it yet: adopt_service adopts it (it changes nothing about the service itself).',
                    default => null,
                },
            ],
            'logsUrl' => $context->url('/logs?'.http_build_query(['machine' => $machine->id, 'service' => $service['name'] ?? $key])),
        ];
    }
}
