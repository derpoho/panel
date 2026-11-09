<?php

namespace App\Events;

use App\Models\User;
use App\Models\Workspace;

/**
 * A workspace was just made for its owner (signing up, from the switcher,
 * or by staff), with its owner a member. Listeners run before
 * CreateWorkspace returns, so what they change is in the workspace it hands
 * back.
 */
final readonly class WorkspaceCreated
{
    public function __construct(
        public Workspace $workspace,
        public User $owner,
    ) {}
}
