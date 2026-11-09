<?php

namespace App\Mcp\Tools\Team;

use App\Mcp\McpContext;
use App\Mcp\Tool;

/**
 * What the person is emailed from this workspace, as their Profile tab
 * shows it.
 */
final class GetMyNotifications extends Tool
{
    public function name(): string
    {
        return 'get_my_notifications';
    }

    public function title(): string
    {
        return 'Get my email preferences';
    }

    public function description(): string
    {
        return 'Returns what you (the person this connection acts as) are emailed from this workspace, as Settings › Profile shows it: `alertEmails` (all: every alert and the “it’s back” emails; problems: only when something breaks; none) and `weeklySummary` (the Monday summary, on or off). '
            .'`getsAlerts` says whether your role is sent alerts at all (only owners and admins are), and `workspaceSendsAlertEmails` whether alert emails are on for the workspace. These are only yours; everyone chooses their own. update_my_notifications changes them.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return Team::notifications($context);
    }
}
