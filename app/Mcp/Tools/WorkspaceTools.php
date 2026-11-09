<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Workspace\DismissIssues;
use App\Mcp\Tools\Workspace\GetActivity;
use App\Mcp\Tools\Workspace\GetWorkspace;
use App\Mcp\Tools\Workspace\ListIssues;
use App\Mcp\Tools\Workspace\Search;

/**
 * The workspace as a whole: what's in it, what needs attention, what
 * happened, and finding things in it.
 */
final class WorkspaceTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            GetWorkspace::class,
            Search::class,
            ListIssues::class,
            DismissIssues::class,
            GetActivity::class,
        ];
    }
}
