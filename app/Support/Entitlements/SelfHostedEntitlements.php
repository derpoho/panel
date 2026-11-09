<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Workspace;

/**
 * A self-hosted install without a license: the free edition. Every workspace
 * is active with no plan, gets every free feature, and none of the paid ones
 * (they come with a license). The customer pays for the hardware, so the
 * numbers are theirs to set in config/serveros.php ('self_hosted'), with
 * machines and AI tool calls unlimited.
 */
class SelfHostedEntitlements implements Entitlements
{
    /** The features the free edition includes. */
    public const array FREE_FEATURES = [Feature::StaffRoles, Feature::StatusPages, Feature::Automations];

    public function active(Workspace $workspace): bool
    {
        return true;
    }

    public function has(Workspace $workspace, Feature $feature): bool
    {
        return in_array($feature, self::FREE_FEATURES, true);
    }

    public function limit(Workspace $workspace, Limit $limit): ?int
    {
        $value = config('serveros.self_hosted.'.$limit->value);

        return $value === null ? null : (int) $value;
    }
}
