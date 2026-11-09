<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\MachinePolicy;
use App\Support\Machines\MachinePolicies;

/**
 * The workspace's machine policies: what machines following each collect
 * and send.
 */
final class ListMachinePolicies extends Tool
{
    public function name(): string
    {
        return 'list_machine_policies';
    }

    public function title(): string
    {
        return 'List machine policies';
    }

    public function description(): string
    {
        return 'Lists the workspace’s machine policies: named sets of what a machine’s daemon does. Each has discovery (finds running services on its own), logs (sends service logs), logIpMasking (off, partial or hash), securityWatch (reads SSH settings and failed sign-ins), errorReports (the daemon sends its crash and error reports, scrubbed), statusPageNewServices (a new status page shows services as they’re found) and updates (automatic, or approve to wait for someone to install each one), plus how many machines follow it. A machine follows its own policy, else its group’s, else the default (one is marked default). Health (CPU, memory, disk, network) is always sent. Use a policy’s id or name with add_machine, update_machine or the machine group tools.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        MachinePolicies::defaultFor($context->workspace);
        $policies = $context->workspace->machinePolicies()->orderByDesc('is_default')->orderBy('name')->get();

        return [
            'policies' => $policies->map(fn (MachinePolicy $policy) => MachinePolicyRefs::present($policy, MachinePolicies::following($policy)->count()))->all(),
            'url' => $context->url('/settings/machine-policies'),
        ];
    }
}
