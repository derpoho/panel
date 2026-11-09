<?php

namespace App\Mcp\Tools\Service;

/**
 * Ask a service to reload its configuration, as "Reload config" on its
 * page.
 */
final class ReloadService extends ServiceAction
{
    public function name(): string
    {
        return 'reload_service';
    }

    public function title(): string
    {
        return 'Reload a service’s configuration';
    }

    public function description(): string
    {
        return 'Asks a service to reload its configuration without a full restart (like systemctl reload), as “Reload config” on its page does. Not every service supports reloading; if it doesn’t, the job fails and says so. The service must be adopted by ServerOS (managed: true) and the machine online. Runs as a job: pass wait (up to 25 seconds) or follow it with get_job.';
    }

    protected function action(): string
    {
        return 'reload';
    }

    protected function doing(): string
    {
        return 'Reloading';
    }
}
