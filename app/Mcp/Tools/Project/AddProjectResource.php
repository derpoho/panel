<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Projects\Connections;
use App\Support\Projects\ProjectActions;

final class AddProjectResource extends Tool
{
    public function __construct(private readonly ProjectActions $actions) {}

    public function name(): string
    {
        return 'add_project_resource';
    }

    public function title(): string
    {
        return 'Deploy a new database or cache for a project';
    }

    public function description(): string
    {
        return 'Deploys a NEW, empty resource for a project on a machine, as the project page’s toolbar does: a database (Postgres), a cache (Valkey), object storage (MinIO) or websockets (Soketi), with generated passwords. Once it’s up, every app in the environment is connected to it: its settings go into the app, which deploys again. It runs on their real machine, so check with the person first. Only when the person wants a new one: to use a database or cache that’s already running (list_deploys shows them), use add_to_project and then connect_project_app instead.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Projects::schema(),
            'kind' => Schema::string('What to add.', array_keys(Connections::DEFAULTS)),
            'machine' => Schema::machine('The machine to deploy it on: its id or name.'),
            'environment' => Projects::environmentSchema(),
        ], ['project', 'kind', 'machine']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'project' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(Connections::DEFAULTS))],
            'machine' => ['required'],
            'environment' => ['nullable', 'string', 'max:40'],
        ]);
        $project = Projects::find($context, $data['project']);
        $machine = $context->machine($data['machine']);
        $node = $this->actions->addResource($project, $context->user, $data['kind'], $machine, Projects::environment($project, $data['environment'] ?? null));

        return [
            'added' => ['id' => $node->id, 'name' => $node->name, 'kind' => $node->kind, 'template' => $node->template],
            'next' => 'It deploys now; the apps in the environment are connected to it once it’s up (get_project shows each connection’s state).',
            'project' => Projects::view($context, $project),
        ];
    }
}
