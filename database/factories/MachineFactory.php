<?php

namespace Database\Factories;

use App\Enums\MachineEnvironment;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Machine>
 */
class MachineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->bothify('web-##'),
            'ip_address' => fake()->ipv4(),
            'provider' => fake()->randomElement(['Hetzner', 'DigitalOcean', 'Vultr', 'Linode']),
            'environment' => MachineEnvironment::Production,
            'services_count' => fake()->numberBetween(1, 8),
            'memory_used_mb' => fake()->numberBetween(512, 16384),
            'cpu_percent' => fake()->numberBetween(5, 60),
            'last_backup_at' => now()->subMinutes(fake()->numberBetween(5, 120)),
        ];
    }

    /**
     * A machine whose CPU is running hot.
     */
    public function busy(): static
    {
        return $this->state(fn () => ['cpu_percent' => 92]);
    }

    public function neverBackedUp(): static
    {
        return $this->state(fn () => ['last_backup_at' => null]);
    }

    public function environment(MachineEnvironment $environment): static
    {
        return $this->state(fn () => ['environment' => $environment]);
    }
}
