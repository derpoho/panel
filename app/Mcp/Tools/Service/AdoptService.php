<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;

/**
 * Let ServerOS control a service it only found, as "Adopt service" on its
 * page. Nothing about the service itself changes.
 */
final class AdoptService extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'adopt_service';
    }

    public function title(): string
    {
        return 'Adopt a service';
    }

    public function description(): string
    {
        return 'Adopts a service ServerOS discovered but doesn’t control yet (managed: false in list_services), so it can be started, stopped, restarted and reloaded, its logs read and (for containers) commands run in it. Adopting changes nothing about the service itself; it takes effect at the machine’s next scan, a few seconds after the job. The machine must be online. Returns the adopt job; pass wait (up to 25 seconds) or follow it with get_job.';
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

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [...ServiceWork::serviceArguments(), 'wait' => ServiceWork::waitRules()]);
        [$machine, $service] = ServiceWork::find($context, $data);
        if ($service['managed']) {
            throw new ToolError("{$service['display']} is already adopted: ServerOS controls it. Nothing to do.");
        }

        $key = (string) $service['key'];
        $job = $this->work->queue($context, $machine, 'adopt', ['type' => 'adopt', 'service' => $key, 'dry_run' => false], $key);

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'job' => ServiceView::job($context, $context->waitForJob($job, (int) ($data['wait'] ?? 0)), 20),
            'note' => 'Once the job has finished and the machine has rescanned, get_service shows it as managed.',
        ];
    }
}
