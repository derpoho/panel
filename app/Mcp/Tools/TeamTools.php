<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Team\CancelInvitation;
use App\Mcp\Tools\Team\ChangeMemberRole;
use App\Mcp\Tools\Team\GetMyNotifications;
use App\Mcp\Tools\Team\InviteMember;
use App\Mcp\Tools\Team\ListMembers;
use App\Mcp\Tools\Team\ListRoles;
use App\Mcp\Tools\Team\RemoveMember;
use App\Mcp\Tools\Team\UpdateMyNotifications;
use App\Mcp\Tools\Team\UpdateWorkspace;

/**
 * The people in the workspace and how it presents itself: members, roles
 * and invites, the workspace's name and logo, and the person's own email
 * preferences.
 */
final class TeamTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListMembers::class,
            ListRoles::class,
            InviteMember::class,
            CancelInvitation::class,
            ChangeMemberRole::class,
            RemoveMember::class,
            UpdateWorkspace::class,
            GetMyNotifications::class,
            UpdateMyNotifications::class,
        ];
    }
}
