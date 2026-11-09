<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Firewall;

/**
 * What a machine lets in from the internet, as its Ports tab shows it.
 */
final class GetFirewall extends Tool
{
    public function name(): string
    {
        return 'get_firewall';
    }

    public function title(): string
    {
        return 'See a machine’s firewall and open ports';
    }

    public function description(): string
    {
        return 'Shows the machine’s firewall (ufw, nftables or iptables), whether it is on and blocks by default, its rules, and each port listening beyond the machine itself with its exposure: open (anyone on the internet can connect), restricted (only some addresses), blocked, docker (published by a Docker container, which the firewall doesn’t filter) or unknown (rules ServerOS can’t read may decide). what names a database or cache that usually shouldn’t face the internet; ssh marks SSH, which close_port refuses. unavailable says why ports can’t be closed on it, if they can’t. From the last scan (scannedAt): rescan_machine refreshes it. Older daemons don’t report the firewall (supported is false): every port bound beyond loopback then has exposure null and may be reachable.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['machine' => Schema::machine()], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', ServerWork::reference()]]);
        $machine = $context->machine($data['machine']);
        $view = Firewall::view($machine);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'scannedAt' => $machine->inventory_scanned_at?->toIso8601String(),
            'firewall' => $view === null ? null : collect($view)->except('presets')->all(),
            'note' => $view === null ? 'The machine hasn’t reported a scan yet.' : null,
        ];
    }
}
