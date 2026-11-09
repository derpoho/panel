<?php

namespace App\Mcp\Tools\Deploy;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use App\Support\DaemonFacts;
use App\Support\Projects\Connections;

/**
 * What an app on the same machine needs to use a database, cache or other
 * resource ServerOS deployed from a template: the same settings a project
 * link writes (Connections), with the address apps reach it at, the Docker
 * bridge. They hold its password, so only connections that may make
 * changes, for people who manage machines, can read them.
 */
final class GetConnection extends Tool
{
    public function __construct(private readonly Connections $connections) {}

    public function name(): string
    {
        return 'get_connection';
    }

    public function title(): string
    {
        return 'Get a database’s connection settings';
    }

    public function description(): string
    {
        return 'The settings an app on the same machine uses to connect to a database, cache or other resource ServerOS deployed from a template (postgres, mysql, mariadb, mongodb, redis, valkey, minio, soketi): host, port, user, password and URL, under the names apps read (DB_HOST, DB_PASSWORD, REDIS_HOST, DATABASE_URL…). '
            .'The host is the Docker bridge address, which is how an app container reaches it; a container name won’t resolve from another app. Pass them straight into the app’s deploy (extra_env for deploy_template). '
            .'They contain the password: never show it to the person or repeat it in your answer. Resources ServerOS didn’t deploy (adopted ones) have no known settings: ask the person for them.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::string('The name it was deployed as, like postgres or cache (as list_services and list_deploys show).', maxLength: 63),
        ], ['machine', 'service']);
    }

    public function scope(): string
    {
        // It reads a password, which a read-only connection shouldn't.
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMachines;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'service' => ['required', 'string', 'max:63'],
        ]);
        $machine = $context->machine($data['machine']);
        $name = trim((string) $data['service']);

        $deploy = Deploys::lastDeploy($machine, $name);
        $template = $deploy !== null ? $this->connections->templateOf($deploy) : null;
        if ($deploy === null || $template === null) {
            throw new ToolError("“{$name}” on {$machine->name} isn’t a database or cache ServerOS deployed from a template, so its connection settings aren’t known. Ask the person for them, or deploy one with deploy_template.");
        }

        // Read, not changed; the MCP call log keeps who asked.
        AuditContext::nothingChanged();
        $bridge = (bool) (Connections::TEMPLATES[$template]['bridge'] ?? false);
        $host = $bridge ? DaemonFacts::dockerBridge($machine->facts ?? []) : '127.0.0.1';

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'service' => $name,
            'template' => $template,
            'host' => $host,
            'env' => $this->connections->env($template, (array) $deploy->command['job'], $host),
            'note' => $bridge
                ? 'For apps on this machine. Give these to the app as they are; never show the password.'
                : 'It only listens on the machine itself, so apps in containers can’t reach it at 127.0.0.1. Tell the person.',
        ];
    }
}
