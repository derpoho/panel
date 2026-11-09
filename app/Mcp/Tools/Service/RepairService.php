<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Repair a container Pterodactyl set up, as "Repair" on its page: it is
 * recreated without the files Wings used to create, so Docker starts it
 * again, with the same image, settings and data.
 */
final class RepairService extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'repair_service';
    }

    public function title(): string
    {
        return 'Repair a Pterodactyl container';
    }

    public function description(): string
    {
        return 'Repairs a container Pterodactyl set up whose Wings files are gone (details.needs_repair is "wings" in get_service, and list_issues shows it as service.needs_repair), so Docker will start it again. '
            .'It is recreated with the same name, image, environment, ports, user, limits, network and data folders, without the /run/wings mounts, and with restart: unless-stopped so it comes back after a reboot. Its key changes (the result has the new one); its name in ServerOS and icon stay. If the new container won’t start, the old one is put back. '
            .'Ask the person first. Only for adopted containers, on an online machine. Returns the repair job; pass wait (up to 25 seconds) or follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'wait' => ServiceWork::waitSchema(),
        ], ['machine', 'service']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'wait' => ServiceWork::waitRules(),
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);
        ServiceWork::requireManaged($service);
        ServiceWork::requireContainer($service);

        $key = (string) $service['key'];
        $job = $this->work->queue($context, $machine, 'service_repair', ['type' => 'service_repair', 'service' => $key], $key);

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'job' => ServiceView::job($context, $context->waitForJob($job, (int) ($data['wait'] ?? 0)), 20),
            'note' => "Repairing {$service['display']} on {$machine->name}. Once the job succeeds, its result has the new key.",
        ];
    }
}
