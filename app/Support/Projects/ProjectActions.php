<?php

namespace App\Support\Projects;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What can be done to a project, the same from its page (ProjectController)
 * and from AI apps and Ask AI (the project MCP tools): make one, put what's
 * already deployed on it, add a database or cache to it, and connect an
 * app to them. Problems are ValidationExceptions, in words for the person.
 */
final class ProjectActions
{
    public function __construct(
        private readonly ProjectNetwork $network,
        private readonly ProjectLinks $links,
        private readonly Connections $connections,
        private readonly DaemonJobQueue $queue,
        private readonly ServiceTemplateCatalog $catalog,
    ) {}

    public function create(Workspace $workspace, User $user, string $name): Project
    {
        $project = $workspace->projects()->create(['name' => trim($name)]);
        if ($project->id >= Project::MAX_NETWORKS) {
            $project->delete();
            throw ValidationException::withMessages(['name' => 'No more projects can be made on this panel.']);
        }
        $project->production();
        Activity::record($workspace, 'project.create', $project->name, null, $user);

        return $project;
    }

    /** Put a deploy that's already running on the project. */
    public function addExisting(Project $project, User $user, Machine $machine, string $name, ?ProjectEnvironment $environment = null): ProjectNode
    {
        $environment ??= $project->production();
        $deploy = $this->deployed($machine)->firstWhere('name', $name);
        if ($deploy === null) {
            throw ValidationException::withMessages(['name' => "“{$name}” isn’t deployed on {$machine->name}."]);
        }

        $node = $project->nodes()->firstOrCreate(
            ['machine_id' => $machine->id, 'name' => $deploy['name']],
            ['kind' => $deploy['kind'], 'template' => $deploy['template'], 'environment_id' => $environment->id],
        );
        Activity::record($project->workspace_id, 'project.add', "{$deploy['name']} to {$project->name}", $machine, $user);
        $this->network->sync($project, $user);

        return $node;
    }

    /** Let an app use a resource: its settings go into the app, which deploys again. */
    public function link(Project $project, User $user, ProjectNode $app, ProjectNode $resource): ProjectLink
    {
        if (! $app->isApp() || $resource->isApp()) {
            throw ValidationException::withMessages(['resource' => 'Connect an app to a database, cache, storage or websockets.']);
        }
        if ($app->environment_id !== $resource->environment_id) {
            throw ValidationException::withMessages(['resource' => 'An app only connects to services in its own environment.']);
        }

        $link = $project->links()->firstOrCreate(['app_node_id' => $app->id, 'resource_node_id' => $resource->id]);
        Activity::record($project->workspace_id, 'project.link', "{$app->name} → {$resource->name}", null, $user);
        $this->network->sync($project, $user);
        $this->links->connect($link->refresh(), $user);

        return $link->refresh();
    }

    /**
     * Deploy a database, cache, object storage or websockets for the project
     * on a machine, and connect every app in its environment to it once it's
     * up.
     */
    public function addResource(Project $project, User $user, string $kind, Machine $machine, ?ProjectEnvironment $environment = null): ProjectNode
    {
        if (! isset(Connections::DEFAULTS[$kind])) {
            throw ValidationException::withMessages(['kind' => 'Add a database, cache, storage or websockets.']);
        }
        $environment ??= $project->production();
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw ValidationException::withMessages(['machine' => $reason]);
        }

        $template = $this->catalog->find(Connections::DEFAULTS[$kind]) ?? throw new RuntimeException("No template for {$kind}.");
        $name = Placement::freeName($machine, "{$project->uid}-{$template->slug}");
        $deployment = new TemplateDeployment($template, $machine, [
            'service' => $name,
            'port' => Placement::freePort($machine, $template->port),
            'env' => [],
        ]);

        DeployRequest::assertDocker($machine, $user);
        try {
            $this->queue->deploy($machine, $user, $deployment->job());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['machine' => $e->getMessage()]);
        }

        $node = $project->nodes()->create(['machine_id' => $machine->id, 'environment_id' => $environment->id, 'name' => $name, 'kind' => $kind, 'template' => $template->slug]);
        foreach ($environment->nodes()->where('kind', 'app')->get() as $app) {
            $project->links()->firstOrCreate(['app_node_id' => $app->id, 'resource_node_id' => $node->id]);
        }
        Activity::record($project->workspace_id, 'project.resource', "{$template->name} in {$project->name}", $machine, $user);
        $this->network->sync($project, $user);

        return $node;
    }

    /** One of the project's environments, or production when none is given. */
    public function environment(Project $project, mixed $id): ProjectEnvironment
    {
        if ($id === null || $id === '') {
            return $project->production();
        }

        return $project->environments()->whereKey((int) $id)->firstOrFail();
    }

    /**
     * What's deployed on a machine: each name's last successful deploy,
     * as an app, or a resource when it came from a template a project
     * knows how to connect.
     *
     * @return Collection<int, array{name: string, kind: 'app'|'cache'|'database'|'storage'|'websockets', template: string|null}>
     */
    public function deployed(Machine $machine): Collection
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('state', 'succeeded')
            ->whereNotNull('service')
            ->latest()
            // Recent ones are enough to find each name's last deploy.
            ->limit(300)
            ->get()
            ->unique('service')
            ->filter(fn (DaemonJob $job) => self::status($machine, (string) $job->service) !== null)
            ->map(function (DaemonJob $job) {
                $template = $this->connections->templateOf($job);
                $isApp = filled($job->command['job']['repo'] ?? null) || $template === null;

                return [
                    'name' => (string) $job->service,
                    'kind' => $isApp ? 'app' : Connections::TEMPLATES[$template]['kind'],
                    'template' => $isApp ? null : $template,
                ];
            })
            ->values();
    }

    /**
     * Whether a deploy's containers are running, from the machine's last
     * scan: a template's Compose project, or an app's release containers.
     * Null when nothing of it is there any more.
     */
    public static function status(Machine $machine, string $name): ?string
    {
        $found = Placement::containers($machine, $name);
        if ($found === []) {
            return null;
        }

        foreach ($found as $service) {
            if (($service['status'] ?? null) === 'running') {
                return 'running';
            }
        }

        return 'stopped';
    }
}
