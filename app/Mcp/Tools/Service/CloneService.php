<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Machines\ServiceClones;
use Illuminate\Support\Facades\Validator;

/**
 * A copy of a container to try things on, as "Clone" on its page does.
 */
final class CloneService extends Tool
{
    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'clone_service';
    }

    public function title(): string
    {
        return 'Clone a container';
    }

    public function description(): string
    {
        return 'Starts a copy of a container to try things on, as “Clone” on its page does: the same image, command and environment under a new `name` (lowercase letters, digits and dashes, starting with a letter, not taken on the machine). '
            .'Reach it on a host `port` (1024–65535, free on the machine), or on a `domain` with HTTPS (its DNS must point at the machine; a free port is picked for the proxy). `container_port` says which of its ports to put there (default: the first it publishes or exposes). '
            .'`copy_data` true copies its volumes and folders as they are now (databases with their own dump tools); false gives it fresh volumes (config files it mounts are still copied). It never shares the original’s volumes, but it joins the same networks, so it reaches the same databases and services as the original. '
            .'The original is only read. Only adopted containers, on an online machine with daemon v0.1.1-pre.23 or newer. Check the name, port or domain and the data choice with the person first. Returns the clone job; follow it with get_job (the result has the new service’s key).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'name' => Schema::string('The copy’s name, like shop-test.', maxLength: 63),
            'port' => Schema::integer('The host port to reach it on (1024–65535).', 1024, 65535),
            'domain' => Schema::string('A domain to reach it on, with HTTPS, instead of a port.', maxLength: 253),
            'container_port' => Schema::integer('Which of its ports to publish (default: the first it publishes or exposes).', 1, 65535),
            'copy_data' => Schema::boolean('true: copy its data as it is now. false: start empty.'),
            'wait' => ServiceWork::waitSchema(),
        ], ['machine', 'service', 'name', 'copy_data']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'wait' => ServiceWork::waitRules(),
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);
        if (($reason = ServiceClones::unavailable($machine, $service)) !== null) {
            throw new ToolError($reason);
        }
        if (! isset($arguments['port']) && blank($arguments['domain'] ?? null)) {
            throw new ToolError('Say where to reach the copy: a port, or a domain.');
        }
        $validator = Validator::make(ServiceClones::normalise($arguments), ServiceClones::rules($machine));
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }

        $key = (string) $service['key'];
        $job = $this->work->queue($context, $machine, 'service_clone', ServiceClones::job($machine, $key, $validator->validated()), $key, timeoutSecs: 3600);

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'job' => ServiceView::job($context, $context->waitForJob($job, (int) ($data['wait'] ?? 0)), 20),
            'note' => "Cloning {$service['display']} on {$machine->name} as {$arguments['name']}. Once the job succeeds, its result has the copy’s key; it shows in list_services after the next scan.",
        ];
    }
}
