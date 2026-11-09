<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including billing.',
            self::Admin => 'Everything except billing.',
            self::Member => 'Works with machines and services.',
            self::Viewer => 'Read-only access.',
        };
    }

    /**
     * What the role is allowed to do. Everyone in a workspace can view it.
     *
     * @return list<WorkspacePermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => WorkspacePermission::cases(),
            self::Admin => [
                WorkspacePermission::ManageSettings,
                WorkspacePermission::ManageMembers,
                WorkspacePermission::ManageMachines,
            ],
            self::Member => [WorkspacePermission::ManageMachines],
            self::Viewer => [],
        };
    }

    public function can(WorkspacePermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether the role is one of the "staff roles" reserved for Pro and up.
     */
    public function isStaffRole(): bool
    {
        return in_array($this, [self::Member, self::Viewer], true);
    }

    /**
     * Roles that can be given to people invited to a workspace.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }

    /**
     * @return array{value: string, label: string, description: string, staff: bool, permissions: list<string>}
     */
    public function toPageProps(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'staff' => $this->isStaffRole(),
            'permissions' => array_map(fn (WorkspacePermission $permission) => $permission->value, $this->permissions()),
        ];
    }
}
