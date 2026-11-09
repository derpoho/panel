<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\Schema;

/**
 * Stop a running service, as the Stop button on its page. It stays down
 * until someone starts it, so the person confirms by its name.
 */
final class StopService extends ServiceAction
{
    public function name(): string
    {
        return 'stop_service';
    }

    public function title(): string
    {
        return 'Stop a service';
    }

    public function description(): string
    {
        return 'Stops a service on a machine (docker stop, systemctl stop…). It stays down, and whatever depends on it stops working, until it is started again (start_service). Ask the person first, then pass confirm set to the service’s name exactly as list_services shows it (name, or foundAs). The service must be adopted by ServerOS (managed: true) and the machine online. Runs as a job: pass wait (up to 25 seconds) or follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'confirm' => Schema::string('The service’s name exactly (name or foundAs from list_services), to confirm. Ask the person first: it stays down until started again.', maxLength: 300),
            'wait' => ServiceWork::waitSchema(),
        ], ['machine', 'service', 'confirm']);
    }

    public function destructive(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        return [...parent::rules(), 'confirm' => ['nullable', 'string', 'max:300']];
    }

    protected function check(array $data, array $service): void
    {
        ServiceWork::confirm($data, array_values(array_unique(array_filter([$service['display'], $service['name'] ?? null], 'is_string'))), 'service', 'Stopping takes it down until someone starts it again.');
    }

    protected function action(): string
    {
        return 'stop';
    }

    protected function doing(): string
    {
        return 'Stopping';
    }
}
