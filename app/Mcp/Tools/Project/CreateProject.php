<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Projects\ProjectActions;

final class CreateProject extends Tool
{
    public function __construct(private readonly ProjectActions $actions) {}

    public function name(): string
    {
        return 'create_project';
    }

    public function title(): string
    {
        return 'Create a project';
    }

    public function description(): string
    {
        return 'Creates an empty project, with a Production environment, as New project on the Projects page does. Then put what’s already deployed on it with add_to_project, add a database or cache with add_project_resource, and connect the app with connect_project_app.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['name' => Schema::string('What to call it, like Shop.', maxLength: 80)], ['name']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['name' => ['required', 'string', 'max:80']]);
        $project = $this->actions->create($context->workspace, $context->user, $data['name']);

        return Projects::view($context, $project);
    }
}
