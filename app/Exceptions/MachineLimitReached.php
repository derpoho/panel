<?php

namespace App\Exceptions;

use App\Models\Workspace;
use RuntimeException;

/**
 * Thrown when connecting another machine would exceed the workspace's plan.
 */
class MachineLimitReached extends RuntimeException
{
    public static function for(Workspace $workspace): self
    {
        $limit = $workspace->machineLimit();

        return new self($limit === 0
            ? "Workspace [{$workspace->slug}] needs a plan before connecting machines."
            : "Workspace [{$workspace->slug}] has reached its limit of {$limit} machines.");
    }
}
