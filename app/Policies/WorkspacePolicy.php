<?php

namespace App\Policies;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::ManageBilling);
    }

    public function manageSettings(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::ManageSettings);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::ManageMembers);
    }

    public function manageMachines(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::ManageMachines);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user) === WorkspaceRole::Owner;
    }
}
