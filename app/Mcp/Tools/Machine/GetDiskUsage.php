<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Support\Audit\NotAudited;
use App\Support\Machines\DiskUsage;

/**
 * Ask a machine what's using its disk, as "See what's using it" on the
 * machine page does. Nothing is deleted.
 */
final class GetDiskUsage extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'get_disk_usage';
    }

    public function title(): string
    {
        return 'See what’s using a machine’s disk';
    }

    public function description(): string
    {
        return 'Scans a machine’s disk, read-only, like “See what’s using it” on its page: how full the filesystem is (total, used, available bytes), its biggest folders up to three levels deep (dirs, biggest first; partial is true when a huge disk took longer than 45 seconds and some were skipped), the systemd journal’s size, Docker’s usage and what it could reclaim, and suggestions: what looks safe to clear and the command that does it. It deletes nothing: show the person the suggestions and let them decide (or run the command on the machine if they ask). path scans one folder instead of the whole root filesystem, to look deeper. Runs as a job, usually 5–45 seconds: pass wait (up to 25 seconds), or follow it with get_job and call this again with job set to its id for the formatted answer. Needs the machine online, with daemon v0.1.1-pre.19 or newer.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'path' => Schema::string('A folder to scan instead of the whole root filesystem, like /var/lib. Absolute.', null, 500),
            'job' => Schema::string('A disk scan already started (its jobId), to read its answer instead of starting another.', null, 64),
            'wait' => ServerWork::waitSchema(),
        ], ['machine']);
    }

    /**
     * It changes nothing, but it walks the disk as root on the server, which
     * the dashboard keeps to people who manage machines.
     */
    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    #[NotAudited('A read-only scan of the disk')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(ServerWork::trimmed($arguments, 'path', 'job'), [
            'machine' => ['required', ServerWork::reference()],
            'path' => ['nullable', 'string', 'max:500', 'starts_with:/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'job' => ['nullable', 'string', 'max:64'],
            'wait' => ServerWork::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);

        if (($data['job'] ?? '') !== '') {
            $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'disk_usage')->whereKey($data['job'])->first()
                ?? throw new ToolError('No disk scan with that id on this machine.');
        } else {
            if (($reason = DiskUsage::unavailable($machine)) !== null) {
                throw new ToolError($reason);
            }
            ServerWork::throttle($context, 'disk-usage', 10);
            $job = $this->work->queue($context, $machine, 'disk_usage', array_filter(['type' => 'disk_usage', 'path' => $data['path'] ?? null]), DiskUsage::TIMEOUT);
        }
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...DiskUsage::present($job),
        ];
    }
}
