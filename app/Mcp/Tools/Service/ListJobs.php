<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * Recent server jobs, newest first, as the machine page's Deployments and
 * the service page's history list them.
 */
final class ListJobs extends Tool
{
    private const STATES = ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'refused', 'active', 'finished'];

    public function name(): string
    {
        return 'list_jobs';
    }

    public function title(): string
    {
        return 'List jobs';
    }

    public function description(): string
    {
        return 'Lists recent jobs on the workspace’s machines, newest first: deploys, service actions (service_action: start, stop, restart, reload), service_logs, service_exec (commands), adopt, service_remove, backups, package updates, reboots and so on. Filters: machine; service (on that machine: key, name, label or app name; without a machine, the exact key or app name a job is filed under); kind; state (an exact state, or active for queued and running, finished for the rest). '
            .'Automatic rescans (kind discover) are left out unless kind asks for them, as on the machine page. Each job comes with its last 3 log lines; get_job has the rest. limit up to 100 (default 20), offset to page.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only jobs on this machine: its id or name.'),
            'service' => Schema::service('Only jobs for this service.'),
            'kind' => Schema::string('Only this kind of job, like deploy, service_action, service_exec, service_logs, adopt, service_remove, backup_to, package_updates, reboot or discover.', maxLength: 40),
            'state' => Schema::string('Only jobs in this state: queued, running, succeeded, failed, cancelled, timed_out, refused, or active / finished.', self::STATES),
            'limit' => Schema::integer('How many to return (1–100, default 20).', 1, 100),
            'offset' => Schema::integer('How many to skip, to page through (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['sometimes', ServiceWork::reference()],
            'service' => ['sometimes', ServiceWork::reference()],
            'kind' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'state' => ['sometimes', 'string', 'in:'.implode(',', self::STATES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ], ['kind.regex' => 'The kind is a word like deploy or service_action.']);

        $machine = isset($data['machine']) ? $context->machine($data['machine']) : null;
        $query = DaemonJob::query()
            ->with(['machine', 'user'])
            ->whereIn('machine_id', $machine !== null ? [$machine->id] : $context->workspace->machines()->select('id'));

        if (isset($data['service'])) {
            $query->whereIn('service', $this->serviceNames($context, $machine, (string) $data['service']));
        }
        if (isset($data['kind'])) {
            $query->where('kind', $data['kind']);
        } else {
            $query->where('kind', '!=', 'discover');
        }
        if (($data['state'] ?? null) === 'active') {
            $query->whereIn('state', ['queued', 'running']);
        } elseif (($data['state'] ?? null) === 'finished') {
            $query->whereIn('state', McpContext::FINISHED);
        } elseif (isset($data['state'])) {
            $query->where('state', $data['state']);
        }

        $total = (clone $query)->count();
        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);
        $jobs = $query->latest()->orderByDesc('id')->offset($offset)->limit($limit)->get();

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'jobs' => $jobs->map(fn (DaemonJob $job) => ServiceView::job($context, $job, 3))->all(),
        ];
    }

    /**
     * What jobs for the service are filed under: its key, and a deployed
     * app's name for its deploys.
     *
     * @return list<string>
     */
    private function serviceNames(McpContext $context, ?Machine $machine, string $reference): array
    {
        if ($machine === null) {
            $reference = trim($reference);
            if (str_starts_with($reference, 'app:')) {
                $reference = substr($reference, 4);
            }

            return [$reference];
        }

        try {
            $service = $context->service($machine, $reference);
        } catch (ToolError) {
            // A service that's gone still has its history.
            return [trim($reference)];
        }

        return array_values(array_filter([$service['key'], $service['app'] ?? null]));
    }
}
