<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;

/**
 * One machine as its page shows it.
 */
final class GetMachine extends Tool
{
    public function name(): string
    {
        return 'get_machine';
    }

    public function title(): string
    {
        return 'Get a machine';
    }

    public function description(): string
    {
        return 'Everything about one machine, as its page in the panel shows it: status, environment, group, IP and provider; facts (hostname, OS and version, kernel, architecture, CPU cores and model, total memory, whether Docker is there and its version, boot time, hosting region and zone, network interfaces); usage (CPU %, memory used and total, each disk’s size, use and %); '
            .'a summary of its services by status, with any that aren’t running; its service groups; backups (schedule, destination, last backup and the latest run); maintenance window; its status page (address, whether it’s live) and whether the fleet status page shows it; the latest updates check (how many waiting, security ones, reboot needed); its last 10 jobs; its scheduled tasks (cron jobs and systemd timers: the command, schedule and when in plain words, user, last and next run where timers say, and the service it belongs to where that’s clear); and panel URLs. '
            .'Use list_services for the services themselves.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['machine' => Schema::machine()], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', ServerWork::reference()]]);

        return MachineView::detail($context->machine($data['machine']), $context);
    }
}
