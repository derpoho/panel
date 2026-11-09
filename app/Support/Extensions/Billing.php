<?php

namespace App\Support\Extensions;

use App\Enums\Currency;
use App\Models\Workspace;

/**
 * How a workspace's plan is paid for, when a package bills it. The
 * workspace's billing summary, the currency its prices are in
 * (Currency::forWorkspace) and a card trial waiting on its first charge
 * ask this, never a payment provider. Without a package, NoBilling
 * answers that nothing is billed.
 */
interface Billing
{
    /** Whether a subscription pays for the workspace's plan right now (active, or a payment that's late). */
    public function isBilled(Workspace $workspace): bool;

    /** The currency the workspace's subscription is charged in, while it has one; null to go by the visitor. */
    public function currency(Workspace $workspace): ?Currency;

    /**
     * What the workspace's pages are told about its subscription, for
     * Workspace::billingSummary: its status, whether there's a portal to
     * manage it in, and the contract it's on, if it has one.
     *
     * @return array{subscriptionStatus: string|null, hasBillingPortal: bool, contract: array{key: string, label: string, machines: int, included: int, extra: int, price: int, extraPrice: int, interval: string}|null}
     */
    public function summary(Workspace $workspace): array;
}
