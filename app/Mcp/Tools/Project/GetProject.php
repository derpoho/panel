<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;

final class GetProject extends Tool
{
    public function name(): string
    {
        return 'get_project';
    }

    public function title(): string
    {
        return 'Get a project';
    }

    public function description(): string
    {
        return 'One project as its page shows it: its environments, its parts (apps and resources, with their machine and whether they’re running), the connections between apps and resources (their state, and the names of the settings each wrote into the app), and its private network on each machine.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Projects::schema()], ['project']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['project' => ['required', 'string', 'max:80']]);

        return Projects::view($context, Projects::find($context, $data['project']));
    }
}
