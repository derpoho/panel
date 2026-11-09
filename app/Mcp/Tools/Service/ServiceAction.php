<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Start, stop, restart or reload an adopted service, as the buttons on its
 * page do: one service_action job on the machine, noted in the activity.
 */
abstract class ServiceAction extends Tool
{
    public function __construct(protected readonly ServiceWork $work) {}

    /** start, stop, restart or reload. */
    abstract protected function action(): string;

    /** What the job does, for the answer: "Restarting". */
    abstract protected function doing(): string;

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

    /**
     * @param  array<string, mixed>  $data  validated
     * @param  array<string, mixed>  $service
     */
    protected function check(array $data, array $service): void {}

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [...ServiceWork::serviceArguments(), 'wait' => ServiceWork::waitRules()];
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, $this->rules());
        [$machine, $service] = ServiceWork::find($context, $data);
        ServiceWork::requireManaged($service);
        $this->check($data, $service);

        $key = (string) $service['key'];
        $job = $this->work->queue($context, $machine, 'service_action', ['type' => 'service_action', 'service' => $key, 'action' => $this->action()], $key);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name], 'statusBefore' => $service['status'] ?? 'unknown'],
            'job' => ServiceView::job($context, $job, 20),
            'note' => match ($job->state) {
                'succeeded' => 'Done. The machine rescans after this, so list_services and get_service show the new status within a few seconds.',
                'queued', 'running' => "{$this->doing()} {$service['display']} on {$machine->name}. Follow it with get_job.",
                default => 'It didn’t work: see job.error.',
            },
        ];
    }
}
