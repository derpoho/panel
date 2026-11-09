<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Recent deploys, newest first, as the machine page's Deployments tab
 * lists them: on one machine or across the workspace.
 */
final class ListDeploys extends Tool
{
    private const STATES = ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];

    public function name(): string
    {
        return 'list_deploys';
    }

    public function title(): string
    {
        return 'List deploys';
    }

    public function description(): string
    {
        return 'Lists recent deploys, newest first, as the machine page’s Deployments tab does: on one machine, or across every machine in the workspace. Each has its id (for get_job, redeploy_app’s job, or retry_job), the machine, the app (the name it was deployed as), state (queued, running, succeeded, failed, cancelled, timed_out or refused), phase and progress, kind ("app" built from code or "stack" from a template), source (git or upload), the repository (Git only), the commit, domains, port, whether it’s the app’s live version, who started it, the error and its suggested next step when it failed, and when. '
            .'Settings values are never included. Filter by app (needs machine) and state; page with before (the id of the last deploy of the previous page).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only deploys on this machine: its id or name. Leave out for every machine.'),
            'app' => Schema::string('Only deploys of this app: the name it was deployed as. Needs machine.', maxLength: 300),
            'state' => Schema::string('Only deploys in this state; "active" means queued or running.', [...self::STATES, 'active']),
            'limit' => Schema::integer('How many (1–100, default 20).', 1, 100),
            'before' => Schema::string('A deploy id: list the ones older than it, to page.', maxLength: 64),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['sometimes', 'nullable', Deploys::reference()],
            'app' => ['sometimes', 'nullable', 'string', 'max:300'],
            'state' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', [...self::STATES, 'active'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $machine = filled($data['machine'] ?? null) ? $context->machine($data['machine']) : null;
        if (filled($data['app'] ?? null) && $machine === null) {
            throw new ToolError('Say which machine the app is on: app names are per machine.');
        }
        $app = $machine !== null && filled($data['app'] ?? null) ? Deploys::appName($context, $machine, $data['app']) : null;

        $machines = $machine !== null ? new EloquentCollection([$machine]) : $context->workspace->machines()->get();
        $query = DaemonJob::query()
            ->whereIn('machine_id', $machines->modelKeys())
            ->where('kind', 'deploy')
            ->when($app !== null, fn ($q) => $q->where('service', $app))
            ->when(($data['state'] ?? null) === 'active', fn ($q) => $q->whereIn('state', ['queued', 'running']))
            ->when(in_array($data['state'] ?? null, self::STATES, true), fn ($q) => $q->where('state', $data['state']));

        if (filled($data['before'] ?? null)) {
            $cursor = (clone $query)->where('id', $data['before'])->first();
            if ($cursor === null) {
                throw new ToolError('before must be the id of a deploy from the previous page.');
            }
            $query->where(fn ($q) => $q->where('created_at', '<', $cursor->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id)));
        }

        $limit = (int) ($data['limit'] ?? 20);
        $jobs = $query->with(['user:id,name'])->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get();
        $more = $jobs->count() > $limit;
        $jobs = $jobs->take($limit);

        $byId = $machines->keyBy('id');
        $jobs->each(fn (DaemonJob $job) => $job->setRelation('machine', $byId[$job->machine_id]));
        $live = self::liveIds($jobs);

        return [
            'deploys' => $jobs->map(fn (DaemonJob $job) => Deploys::present($job, $context, $live[$job->machine_id] ?? []))->values()->all(),
            'more' => $more,
            'nextBefore' => $more ? $jobs->last()?->id : null,
        ];
    }

    /**
     * For the apps listed, the id of the deploy that's live: the latest
     * successful one, from code where there is one (as the panel decides
     * which container is live).
     *
     * @param  Collection<int, DaemonJob>  $jobs  with their machines
     * @return array<int, array<string, string>> machine id => app => deploy id
     */
    public static function liveIds(Collection $jobs): array
    {
        $live = [];
        foreach ($jobs->unique(fn (DaemonJob $job) => $job->machine_id.'/'.$job->service) as $job) {
            if (is_string($job->service) && ($deploy = Deploys::lastDeploy($job->machine, $job->service)) !== null) {
                $live[$job->machine_id][$job->service] = $deploy->id;
            }
        }

        return $live;
    }
}
