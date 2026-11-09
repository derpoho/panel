<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;

/**
 * Where an updates check, install or reboot is, and what it found, as
 * the updates dialog follows it.
 */
final class GetUpdatesResult extends Tool
{
    public function name(): string
    {
        return 'get_updates_result';
    }

    public function title(): string
    {
        return 'Get an updates check or install';
    }

    public function description(): string
    {
        return 'Follows an operating system updates job on a machine (from check_updates, install_updates or reboot_machine): its state (queued, running, succeeded, failed, or stale when the daemon has gone quiet), the last lines of its log, any error, and once it has a result, the report: package manager, every waiting package (name, from and to versions, security or not), how many are security updates, whether a reboot is needed, and whether they were installed. Leave job out for the machine’s latest check or install (a reboot needs its job id). canPick says whether install_updates can take a list of packages on this machine.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'job' => Schema::string('The job id (jobId from check_updates or install_updates, or the job id from reboot_machine). Leave out for the latest check or install.', maxLength: 64),
            'log_lines' => Schema::integer('How many of the last log lines to include (0–300, default 60).', 0, 300),
        ], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServerWork::reference()],
            'job' => ['sometimes', 'nullable', 'uuid'],
            'log_lines' => ['sometimes', 'integer', 'min:0', 'max:300'],
        ]);
        $machine = $context->machine($data['machine']);

        $query = DaemonJob::query()->where('machine_id', $machine->id)->whereIn('kind', MachineView::UPDATE_KINDS);
        $job = filled($data['job'] ?? null)
            ? (clone $query)->whereKey($data['job'])->first()
            : (clone $query)->whereIn('kind', ['package_updates', 'package_install', 'package_install_versions'])->latest()->first();
        if ($job === null) {
            throw new ToolError(filled($data['job'] ?? null)
                ? "No updates job “{$data['job']}” on {$machine->name}."
                : "{$machine->name} hasn’t been checked for updates yet. check_updates does that.");
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...MachineView::updatesJob($machine, $job, (int) ($data['log_lines'] ?? 60)),
        ];
    }
}
