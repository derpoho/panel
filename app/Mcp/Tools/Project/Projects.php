<?php

namespace App\Mcp\Tools\Project;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Support\Projects\ProjectActions;

/**
 * What the project tools share: finding a project, one of its parts or
 * environments by id or name, and describing a project the way its page
 * shows it.
 */
final class Projects
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return Schema::string('The project: its id (the end of its address, like bdvrhz14sn) or its name.', maxLength: 80);
    }

    /** @return array<string, mixed> */
    public static function environmentSchema(): array
    {
        return Schema::string('The environment, by id or name (like Production or Staging). Defaults to Production.', maxLength: 40);
    }

    public static function find(McpContext $context, mixed $reference): Project
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which project: its id or name. list_projects shows them.');
        }
        $query = $context->workspace->projects();
        $project = (clone $query)->where('uid', strtolower($reference))->first()
            ?? (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->first();

        return $project ?? throw new ToolError("No project “{$reference}” in {$context->workspace->name}. list_projects shows them.");
    }

    public static function environment(Project $project, mixed $reference): ?ProjectEnvironment
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }
        $found = ctype_digit($reference)
            ? $project->environments()->whereKey((int) $reference)->first()
            : $project->environments()->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->first();

        return $found ?? throw new ToolError("{$project->name} has no environment “{$reference}”. get_project shows its environments.");
    }

    /** One of the project's parts, by its id or its name (in the environment, when there are several). */
    public static function node(Project $project, mixed $reference, ?ProjectEnvironment $environment = null): ProjectNode
    {
        $reference = trim((string) $reference);
        $query = $project->nodes()->when($environment !== null, fn ($q) => $q->where('environment_id', $environment->id));
        $matches = ctype_digit($reference)
            ? (clone $query)->whereKey((int) $reference)->get()
            : (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several parts of {$project->name} are called “{$reference}” (one per environment): use the id, or say the environment.");
        }

        throw new ToolError("Nothing called “{$reference}” is in {$project->name}. get_project shows what is; add_to_project puts a deploy on it.");
    }

    /**
     * The project as its page shows it.
     *
     * @return array<string, mixed>
     */
    public static function view(McpContext $context, Project $project): array
    {
        $project->production();
        $project->load(['nodes.machine', 'links.app', 'links.resource', 'members.machine', 'environments']);

        return [
            'id' => $project->uid,
            'name' => $project->name,
            'url' => $context->url("/projects/{$project->uid}"),
            'environments' => $project->environments->map(fn (ProjectEnvironment $e) => ['id' => $e->id, 'name' => $e->name])->values()->all(),
            'parts' => $project->nodes->map(fn (ProjectNode $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'kind' => $node->kind,
                'template' => $node->template,
                'environment' => $node->environment_id,
                'machine' => ['id' => $node->machine_id, 'name' => $node->machine->name],
                'status' => ProjectActions::status($node->machine, $node->name),
            ])->values()->all(),
            'connections' => $project->links->map(fn (ProjectLink $link) => [
                'id' => $link->id,
                'app' => $link->app->name,
                'resource' => $link->resource->name,
                'state' => $link->state,
                'error' => $link->error,
                // Names only: the values are the app's settings.
                'settings' => $link->env ?? [],
            ])->values()->all(),
            'network' => $project->members->map(fn (ProjectMachine $member) => [
                'machine' => $member->machine->name,
                'state' => $member->state,
                'error' => $member->error,
            ])->values()->all(),
        ];
    }
}
