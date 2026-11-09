<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Projects\ProjectActions;

final class AddToProject extends Tool
{
    public function __construct(private readonly ProjectActions $actions) {}

    public function name(): string
    {
        return 'add_to_project';
    }

    public function title(): string
    {
        return 'Add a deploy to a project';
    }

    public function description(): string
    {
        return 'Puts something already deployed by ServerOS on a project: an app, or a database, cache, storage or websockets from a template. It keeps running where it is; nothing restarts. Give the machine and the name it was deployed as (list_deploys shows them). Adding the same thing twice does nothing. To connect an app to a database, then call connect_project_app.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Projects::schema(),
            'machine' => Schema::machine(),
            'service' => Schema::string('The name it was deployed as, like shop or postgres.', maxLength: 63),
            'environment' => Projects::environmentSchema(),
        ], ['project', 'machine', 'service']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    /**
     * The deployed name meant: any case, or one of its containers' names
     * (serveros-postgres-postgres-1 is postgres).
     *
     * @param  list<string>  $names
     */
    private static function deployName(array $names, string $given): string
    {
        $given = trim($given);
        foreach ($names as $name) {
            if (strcasecmp($name, $given) === 0) {
                return $name;
            }
        }
        foreach ($names as $name) {
            if (preg_match('/^\/?serveros-'.preg_quote($name, '/').'(-[a-z0-9_]+-\d+|-[0-9a-f]{12})$/i', $given) === 1) {
                return $name;
            }
        }

        return $given;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'project' => ['required', 'string', 'max:80'],
            'machine' => ['required'],
            'service' => ['required', 'string', 'max:63'],
            'environment' => ['nullable', 'string', 'max:40'],
        ]);
        $project = Projects::find($context, $data['project']);
        $machine = $context->machine($data['machine']);
        $deployed = $this->actions->deployed($machine);
        $data['service'] = self::deployName(array_values($deployed->pluck('name')->all()), $data['service']);
        if ($deployed->firstWhere('name', $data['service']) === null) {
            $names = $deployed->pluck('name')->implode(', ');
            throw new ToolError("“{$data['service']}” isn’t something ServerOS deployed on {$machine->name}, or it isn’t there any more.".($names !== '' ? " What can be added: {$names}." : ' Nothing on it can be added yet.'));
        }

        $node = $this->actions->addExisting($project, $context->user, $machine, $data['service'], Projects::environment($project, $data['environment'] ?? null));

        return ['added' => ['id' => $node->id, 'name' => $node->name, 'kind' => $node->kind], 'project' => Projects::view($context, $project)];
    }
}
