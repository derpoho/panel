<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

/** No trial asks for a card: what TrialCards answers when no package binds one. */
final class NoTrialCards implements TrialCards
{
    public function assign(User $owner): ?string
    {
        return null;
    }

    public function needsCard(Workspace $workspace): bool
    {
        return false;
    }

    public function trialDays(Workspace $workspace): int
    {
        return 0;
    }

    public function verifyPath(Workspace $workspace): ?string
    {
        return null;
    }

    public function checkout(Workspace $workspace, Plan $plan, BillingInterval $interval, User $by, string $returnUrl): string
    {
        throw new RuntimeException('Adding a card isn’t set up here.');
    }
}
