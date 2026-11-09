<?php

namespace App\Mcp\Tools\Service;

/**
 * Restart a service, as the Restart button on its page.
 */
final class RestartService extends ServiceAction
{
    public function name(): string
    {
        return 'restart_service';
    }

    public function title(): string
    {
        return 'Restart a service';
    }

    public function description(): string
    {
        return 'Restarts a service on a machine (docker restart, systemctl restart…). It is briefly unavailable while it restarts, so tell the person what you are restarting. The service must be adopted by ServerOS (managed: true in list_services; adopt_service does that) and the machine online. Runs as a job: pass wait (up to 25 seconds) to answer once it has finished, or follow it with get_job.';
    }

    protected function action(): string
    {
        return 'restart';
    }

    protected function doing(): string
    {
        return 'Restarting';
    }
}
