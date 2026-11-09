<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Projects\ProjectActions;

final class ConnectProjectApp extends Tool
{
    public function __construct(private readonly ProjectActions $actions) {}

    public function name(): string
    {
        return 'connect_project_app';
    }

    public function title(): string
    {
        return 'Connect an app to a database';
    }

    public function description(): string
    {
        return 'Connects an app on a project to a database, cache, storage or websockets on the same project and environment, as drawing a line between them on the project page does: ServerOS writes the resource’s settings (host, port, user, password, URL) into the app and deploys it again, across machines through the project’s private network when they’re on different ones. The app restarts with a new release, so check with the person first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Projects::schema(),
            'app' => Schema::string('The app: its id or name on the project.', maxLength: 63),
            'resource' => Schema::string('The database, cache, storage or websockets: its id or name on the project.', maxLength: 63),
            'environment' => Projects::environmentSchema(),
        ], ['project', 'app', 'resource']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'project' => ['required', 'string', 'max:80'],
            'app' => ['required', 'string', 'max:63'],
            'resource' => ['required', 'string', 'max:63'],
            'environment' => ['nullable', 'string', 'max:40'],
        ]);
        $project = Projects::find($context, $data['project']);
        $environment = Projects::environment($project, $data['environment'] ?? null);
        $link = $this->actions->link($project, $context->user, Projects::node($project, $data['app'], $environment), Projects::node($project, $data['resource'], $environment));

        return [
            'connection' => ['id' => $link->id, 'state' => $link->state, 'error' => $link->error, 'settings' => $link->env ?? []],
            'next' => $link->state === 'applied' ? 'The app deploys again with the settings.' : 'It’s waiting: the resource or the private network isn’t up yet, and it connects by itself once they are.',
            'project' => Projects::view($context, $project),
        ];
    }
}
