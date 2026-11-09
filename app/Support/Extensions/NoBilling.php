<?php

namespace App\Support\Extensions;

use App\Enums\Currency;
use App\Models\Workspace;

/** Nothing is billed: what Billing answers when no package binds one. */
final class NoBilling implements Billing
{
    public function isBilled(Workspace $workspace): bool
    {
        return false;
    }

    public function currency(Workspace $workspace): ?Currency
    {
        return null;
    }

    public function summary(Workspace $workspace): array
    {
        return ['subscriptionStatus' => null, 'hasBillingPortal' => false, 'contract' => null];
    }
}
