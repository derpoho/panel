<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Let the internet reach a port, as the Ports tab's Open button does.
 */
final class OpenPort extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'open_port';
    }

    public function title(): string
    {
        return 'Open a port to the internet';
    }

    public function description(): string
    {
        return 'Lets anyone on the internet connect to a port on the machine (for example 80 and 443 for a website). The machine removes rules that block exactly that port and adds an allow if one is still needed, then checks the port really is open. Don’t open databases or caches (see get_firewall’s what) unless the person asks for it. Refused when nftables rules outside ServerOS’s own block it. The machine must be online and its daemon recent enough to report its firewall. Returns the job; with wait (seconds) it waits for the result.';
    }

    public function inputSchema(): array
    {
        return Schema::object(PortChange::schema(), ['machine', 'port']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, PortChange::rules());

        return PortChange::run($context, $this->work, $context->machine($data['machine']), 'open', $data);
    }
}
