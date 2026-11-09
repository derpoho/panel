<?php

namespace App\Support;

use App\Models\User;
use ServerOS\Saas\Cloud\CloudPricing;

/**
 * ServerOS Cloud's sizes and prices as the marketing site, the docs and
 * the dashboard ask for them. Cloud comes from a package (CloudPricing), so
 * on an install without it, like a self-hosted one, there's no Cloud to
 * offer: null, as when it isn't open.
 */
final class CloudOffer
{
    /** @return array{credit: string|null, throttledMbit: int, sizes: array<int, array<string, mixed>>}|null */
    public static function forMarketing(?User $user): ?array
    {
        return class_exists(CloudPricing::class) ? CloudPricing::forMarketing($user) : null;
    }
}
