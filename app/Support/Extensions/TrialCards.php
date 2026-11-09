<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * A card before the trial: whether a new signup's workspace has to add one
 * before its dashboard opens, and the checkout that takes it. The welcome
 * flow's plan step and workspace creation ask this, never a payment
 * provider. A package binds one here; without one, NoTrialCards answers
 * that no trial needs a card.
 */
interface TrialCards
{
    /** Which group of the test a new signup's workspace goes in, or null for none. */
    public function assign(User $owner): ?string;

    /** Whether the workspace waits on the welcome flow until it adds a card. */
    public function needsCard(Workspace $workspace): bool;

    /** The free days the card's checkout gives before charging: what's left of the trial. */
    public function trialDays(Workspace $workspace): int;

    /** Where the welcome flow confirms the card when checkout sends them back, on the workspace's host. */
    public function verifyPath(Workspace $workspace): ?string;

    /**
     * Checkout for the card, for the plan chosen, landing back on $returnUrl.
     *
     * @return string The checkout's address.
     *
     * @throws ConnectionException|RuntimeException When checkout can't start; the message says why.
     */
    public function checkout(Workspace $workspace, Plan $plan, BillingInterval $interval, User $by, string $returnUrl): string;
}
