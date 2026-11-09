<?php

namespace App\Mcp\Tools\Service;

/**
 * Start a stopped service, as the Start button on its page.
 */
final class StartService extends ServiceAction
{
    public function name(): string
    {
        return 'start_service';
    }

    public function title(): string
    {
        return 'Start a service';
    }

    public function description(): string
    {
        return 'Starts a service on a machine (docker start, systemctl start…). The service must be adopted by ServerOS (managed: true in list_services; adopt_service does that) and the machine online. Runs as a job: pass wait (up to 25 seconds) to answer once it has finished, or follow it with get_job. The new status shows in get_service once the machine has rescanned, a few seconds after.';
    }

    protected function action(): string
    {
        return 'start';
    }

    protected function doing(): string
    {
        return 'Starting';
    }
}
