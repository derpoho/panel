<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Project\AddProjectResource;
use App\Mcp\Tools\Project\AddToProject;
use App\Mcp\Tools\Project\ConnectProjectApp;
use App\Mcp\Tools\Project\CreateProject;
use App\Mcp\Tools\Project\GetProject;
use App\Mcp\Tools\Project\ListProjects;

/**
 * Projects: an app with the databases and caches it uses, wired together,
 * possibly across machines. Making one, putting deploys on it, adding a
 * resource and connecting an app to it.
 */
final class ProjectTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListProjects::class,
            GetProject::class,
            CreateProject::class,
            AddToProject::class,
            AddProjectResource::class,
            ConnectProjectApp::class,
        ];
    }
}
