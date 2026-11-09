<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\MachineEnvironment;
use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Fills every workspace that has no machines with the fleet from the
 * marketing design, so the dashboard has something to show locally. Moves
 * those workspaces to Business so the 18 machines fit.
 *
 * php artisan db:seed --class=DemoMachinesSeeder
 */
class DemoMachinesSeeder extends Seeder
{
    public function run(): void
    {
        Workspace::doesntHave('machines')->each(function (Workspace $workspace) {
            if ($workspace->plan !== Plan::Business) {
                $workspace->choosePlan(Plan::Business, $workspace->billing_interval ?? BillingInterval::Monthly);
            }

            foreach ($this->machines() as [$name, $ip, $environment, $provider, $services, $memory, $cpu, $backupMinutesAgo]) {
                $workspace->machines()->create([
                    'name' => $name,
                    'ip_address' => $ip,
                    'environment' => $environment,
                    'provider' => $provider,
                    'services_count' => $services,
                    'memory_used_mb' => $memory,
                    'cpu_percent' => $cpu,
                    'last_backup_at' => now()->subMinutes($backupMinutesAgo),
                ]);
            }
        });
    }

    /**
     * Name, IP, environment, provider, services, memory (MB), CPU %, minutes since last backup.
     *
     * @return list<array{string, string, MachineEnvironment, string, int, int, int, int}>
     */
    private function machines(): array
    {
        return [
            ['prod-web-01', '203.0.113.11', MachineEnvironment::Production, 'Hetzner', 6, 6554, 82, 12],
            ['prod-api-01', '203.0.113.12', MachineEnvironment::Production, 'DigitalOcean', 6, 3277, 24, 15],
            ['worker-01', '198.51.100.7', MachineEnvironment::Production, 'Vultr', 3, 2150, 44, 18],
            ['staging-01', '198.51.100.21', MachineEnvironment::Staging, 'Linode', 2, 1434, 38, 21],
            ['db-primary', '203.0.113.40', MachineEnvironment::Production, 'Hetzner', 7, 13107, 70, 24],
            ['cache-01', '203.0.113.41', MachineEnvironment::Production, 'DigitalOcean', 4, 4301, 23, 27],
            ['prod-web-02', '198.51.100.9', MachineEnvironment::Production, 'Vultr', 8, 5939, 86, 30],
            ['prod-api-02', '198.51.100.33', MachineEnvironment::Production, 'Linode', 4, 3686, 51, 33],
            ['worker-02', '203.0.113.52', MachineEnvironment::Production, 'Hetzner', 8, 2458, 38, 36],
            ['queue-01', '203.0.113.60', MachineEnvironment::Production, 'DigitalOcean', 5, 1843, 61, 39],
            ['dev-web-01', '192.0.2.14', MachineEnvironment::Development, 'Vultr', 2, 819, 77, 42],
            ['staging-02', '198.51.100.22', MachineEnvironment::Staging, 'Hetzner', 3, 1946, 29, 45],
            ['staging-db', '198.51.100.23', MachineEnvironment::Staging, 'DigitalOcean', 2, 2662, 47, 48],
            ['staging-worker', '198.51.100.24', MachineEnvironment::Staging, 'Vultr', 2, 1126, 18, 51],
            ['dev-api-01', '192.0.2.15', MachineEnvironment::Development, 'Linode', 2, 614, 12, 54],
            ['dev-db-01', '192.0.2.16', MachineEnvironment::Development, 'Hetzner', 1, 1229, 33, 57],
            ['backup-01', '203.0.113.70', MachineEnvironment::Production, 'Hetzner', 2, 1638, 9, 60],
            ['edge-01', '203.0.113.80', MachineEnvironment::Production, 'Vultr', 3, 922, 41, 60],
        ];
    }
}
