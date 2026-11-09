<?php

namespace App\Enums;

enum WorkspacePermission: string
{
    case ManageBilling = 'manage-billing';
    case ManageSettings = 'manage-settings';
    case ManageMembers = 'manage-members';
    case ManageMachines = 'manage-machines';

    public function label(): string
    {
        return match ($this) {
            self::ManageBilling => 'Change plan and billing',
            self::ManageSettings => 'Edit workspace settings',
            self::ManageMembers => 'Invite and manage members',
            self::ManageMachines => 'Connect and manage machines',
        };
    }
}
