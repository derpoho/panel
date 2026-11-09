<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Stop the internet reaching a port, as the Ports tab's Close button does.
 */
final class ClosePort extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'close_port';
    }

    public function title(): string
    {
        return 'Close a port to the internet';
    }

    public function description(): string
    {
        return 'Stops the internet reaching a port on the machine; programs on the machine itself still can. Anything that connects to it from elsewhere (another server, someone’s laptop, a website’s visitors) is cut off, so ask the person first and pass confirm set to the machine’s name exactly. The machine removes the allow rule for exactly that port, or adds a deny ahead of broader rules, then checks the port really is closed. Refused for SSH (it could lock everyone out), for ports a Docker container publishes (the firewall doesn’t filter those; publish it on 127.0.0.1 instead), and when the firewall is off or not installed (get_firewall’s unavailable says why). Returns the job; with wait (seconds) it waits for the result.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            ...PortChange::schema(),
            'confirm' => Schema::confirm('the machine’s name'),
        ], ['machine', 'port', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, PortChange::rules());
        $machine = $context->machine($data['machine']);
        ServerWork::confirm($data, $machine->name, 'machine', "Closing port {$data['port']} cuts off anything that reaches it from outside the machine.");

        return PortChange::run($context, $this->work, $machine, 'close', $data);
    }
}
