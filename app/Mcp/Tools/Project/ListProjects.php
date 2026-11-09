<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\Project;

final class ListProjects extends Tool
{
    public function name(): string
    {
        return 'list_projects';
    }

    public function title(): string
    {
        return 'List projects';
    }

    public function description(): string
    {
        return 'Lists the workspace’s projects. A project groups an app with the databases, caches, storage and websockets it uses, possibly across machines (joined by a private network of their own), and wires their connection settings into the app; it can have environments like Staging copied from Production. Returns each project’s id, name, how many apps and resources it has, and its address.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return [
            'projects' => $context->workspace->projects()->with('nodes:id,project_id,kind')->withCount('environments')->latest()->get()
                ->map(fn (Project $project) => [
                    'id' => $project->uid,
                    'name' => $project->name,
                    'apps' => $project->nodes->where('kind', 'app')->count(),
                    'resources' => $project->nodes->where('kind', '!=', 'app')->count(),
                    'environments' => $project->environments_count,
                    'url' => $context->url("/projects/{$project->uid}"),
                ])->values()->all(),
            'url' => $context->url('/projects'),
        ];
    }
}
