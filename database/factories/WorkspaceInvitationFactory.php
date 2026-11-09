<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token' => hash('sha256', fake()->unique()->sha1()),
            'expires_at' => now()->addDays(WorkspaceInvitation::EXPIRES_AFTER_DAYS),
        ];
    }

    /**
     * An invitation whose link is known, for tests that follow it.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn () => ['token' => hash('sha256', $plainToken)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
