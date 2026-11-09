<?php

namespace App\Support\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;

/**
 * A role picked for someone joining: a built-in role (admin, member,
 * viewer) or `custom:{id}` for one of the workspace's own roles, which
 * sits on top of Viewer, the same as invites.
 */
final class RoleChoice
{
    private function __construct(
        public readonly WorkspaceRole $role,
        public readonly ?WorkspaceCustomRole $custom,
    ) {}

    /**
     * The choice, or why it can't be made on this workspace's plan.
     */
    public static function parse(Workspace $workspace, mixed $value): self|string
    {
        $value = is_string($value) ? $value : '';

        if (preg_match('/^custom:(\d+)$/', $value, $m) === 1) {
            if (! $workspace->hasCustomRoles()) {
                return 'Custom roles are on Business.';
            }
            $custom = $workspace->customRoles()->find((int) $m[1]);

            return $custom === null ? 'That role no longer exists.' : new self(WorkspaceRole::Viewer, $custom);
        }

        $role = WorkspaceRole::tryFrom($value);
        if ($role === null || ! in_array($role, WorkspaceRole::assignable(), true)) {
            return 'Pick a role.';
        }
        if ($role->isStaffRole() && ! $workspace->hasStaffRoles()) {
            return 'Member and Viewer roles are on Pro and Business.';
        }

        return new self($role, null);
    }

    /**
     * A choice already known to be allowed (read back from a saved
     * setting, say). A custom role sits on top of Viewer.
     */
    public static function of(WorkspaceRole $role, ?WorkspaceCustomRole $custom): self
    {
        return new self($custom !== null ? WorkspaceRole::Viewer : $role, $custom);
    }

    /** Whether it's the role someone already has. */
    public function matches(string $role, ?int $customRoleId): bool
    {
        return $this->role->value === $role && $this->custom?->id === $customRoleId;
    }

    /** What people see: the custom role's name, or the built-in label. */
    public function label(): string
    {
        return $this->custom->name ?? $this->role->label();
    }

    /** As the pickers send it back. */
    public static function value(string $role, ?int $customRoleId): string
    {
        return $customRoleId !== null ? "custom:{$customRoleId}" : $role;
    }
}
