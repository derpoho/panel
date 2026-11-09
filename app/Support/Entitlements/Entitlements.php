<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Workspace;

/**
 * What a workspace may use, in one place. serveros.com answers from the
 * workspace's plan (PlanEntitlements); a self-hosted install answers from its
 * edition (SelfHostedEntitlements) and the workspace's license. Code asks
 * the workspace (hasFeature, limitOf, isActive), never the plan, so the
 * answer can change without touching it.
 * It's limitOf rather than limit because Eloquent keeps limit for queries.
 * Chosen by config('serveros.edition') in AppServiceProvider.
 */
interface Entitlements
{
    /**
     * Whether the workspace is running at all: on serveros.com it has a plan
     * or a trial. Without it, nothing new runs (deploys, monitors, the API,
     * AI apps), though everything already running keeps going.
     */
    public function active(Workspace $workspace): bool;

    public function has(Workspace $workspace, Feature $feature): bool;

    /** The limit's value, or null for none. */
    public function limit(Workspace $workspace, Limit $limit): ?int;
}
