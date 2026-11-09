<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\GitHubInstallation;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\DeployRequest;
use App\Support\GitHub\GitHubApp;
use App\Support\Projects\AppSettings;
use App\Support\Projects\Connections;
use App\Support\Projects\Environments;
use App\Support\Projects\Placement;
use App\Support\Projects\Previews;
use App\Support\Projects\ProjectActions;
use App\Support\Projects\ProjectData;
use App\Support\Projects\ProjectLinks;
use App\Support\Projects\ProjectNetwork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Projects: an app and the resources it uses, drawn as a canvas grouped by
 * machine, with the parts joined by a private network of their own.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectNetwork $network,
        private readonly ProjectLinks $links,
        private readonly ProjectActions $actions,
    ) {}

    public function index(Workspace $workspace): Response
    {
        $projects = $workspace->projects()
            ->withCount(['nodes', 'members', 'links'])
            ->with(['nodes:id,project_id,kind', 'members:id,project_id,state'])
            ->latest()
            ->get();

        return Inertia::render('workspace/Projects', [
            'workspace' => $workspace->toPageProps(),
            'projects' => $projects->map(fn (Project $project) => [
                'uid' => $project->uid,
                'name' => $project->name,
                'apps' => $project->nodes->where('kind', 'app')->count(),
                'resources' => $project->nodes->where('kind', '!=', 'app')->count(),
                'machines' => $project->members_count,
                'links' => $project->links_count,
                'network' => self::networkState($project->members->map(fn (ProjectMachine $member) => $member->state)->values()->all()),
                'createdAt' => $project->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $project = $this->actions->create($workspace, $request->user(), $data['name']);

        return to_route('workspace.projects.show', [$workspace, $project]);
    }

    public function show(Request $request, Workspace $workspace, Project $project, Environments $environments, AppSettings $settings): Response
    {
        $this->owned($workspace, $project);

        $project->production();
        $project->load(['nodes.machine', 'links', 'members.machine', 'environments']);
        $machines = $workspace->machines()->orderBy('name')->get();
        $onCanvas = $project->nodes->map(fn (ProjectNode $node) => $node->machine_id.'/'.$node->name)->all();

        return Inertia::render('workspace/ProjectShow', [
            'workspace' => $workspace->toPageProps(),
            'project' => [
                'uid' => $project->uid,
                'name' => $project->name,
                'cidr' => $project->cidr(),
            ],
            'environments' => $project->environments->map(fn (ProjectEnvironment $environment) => [
                'id' => $environment->id,
                'name' => $environment->name,
                'source' => $environment->source_id,
                'pullRequest' => $environment->pull_request,
                'head' => $environment->head ? substr($environment->head, 0, 7) : null,
                'refresh' => $environment->refresh,
                'refreshedAt' => $environment->refreshed_at?->toIso8601String(),
            ])->values(),
            'nodes' => $project->nodes->map(function (ProjectNode $node) {
                $containers = Placement::containers($node->machine, $node->name);
                $status = ProjectActions::status($node->machine, $node->name);

                return [
                    'id' => $node->id,
                    'environment' => $node->environment_id,
                    'name' => $node->name,
                    'kind' => $node->kind,
                    'template' => $node->template,
                    'origin' => $node->origin_id,
                    'data' => $node->data_state === null ? null : [
                        'state' => $node->data_state,
                        'error' => $node->data_error,
                        'at' => $node->data_at?->toIso8601String(),
                    ],
                    'machine' => ['id' => $node->machine_id, 'name' => $node->machine->name],
                    'status' => $status,
                    // Nothing of it on the machine because its last deploy didn't work.
                    'failed' => $status === null && DaemonJob::query()
                        ->where('machine_id', $node->machine_id)->where('kind', 'deploy')->where('service', $node->name)
                        ->latest()->value('state') === 'failed',
                    // Its page on the machine, while it has containers there.
                    'href' => $containers === [] ? null
                        : "/machines/{$node->machine_id}/services/".$containers[0]['key'],
                    'port' => $node->isApp() ? null : $this->links->port($node),
                ];
            })->values(),
            'links' => $project->links->map(fn (ProjectLink $link) => [
                'id' => $link->id,
                'app' => $link->app_node_id,
                'resource' => $link->resource_node_id,
                'state' => $link->state,
                'error' => $link->error,
                'env' => $link->env ?? [],
            ])->values(),
            'members' => $project->members->map(fn (ProjectMachine $member) => [
                'machine' => $member->machine_id,
                'address' => $member->setRelation('project', $project)->address(),
                'state' => $member->state,
                'error' => $member->error,
            ])->values(),
            // Deploys that could go on the canvas: every live one, not there yet.
            'candidates' => Inertia::optional(fn () => $machines->flatMap(fn (Machine $machine) => $this->actions->deployed($machine)
                ->reject(fn (array $deploy) => in_array($machine->id.'/'.$deploy['name'], $onCanvas, true))
                ->map(fn (array $deploy) => [...$deploy, 'machine' => ['id' => $machine->id, 'name' => $machine->name]]))
                ->values()),
            'machines' => $machines->map(fn (Machine $machine) => ['id' => $machine->id, 'name' => $machine->name])->values(),
            'resourceKinds' => collect(Connections::DEFAULTS)->map(fn (string $template, string $kind) => ['kind' => $kind, 'template' => $template])->values(),
            'copyable' => ProjectData::COPYABLE,
            // What the last change couldn't do or left out, said once.
            'notes' => array_values(array_map('strval', (array) $request->session()->get('project_notes', []))),
            'previews' => (function () use ($project) {
                $settings = Previews::settings($project);

                return [
                    'enabled' => $settings['enabled'],
                    'repo' => $settings['repo'],
                    'machine' => $settings['machine_id'],
                    'copyData' => $settings['copy_data'],
                    'domain' => $settings['domain'],
                    'limit' => $settings['limit'],
                    'max' => Previews::MAX,
                    'github' => GitHubApp::enabled() && GitHubInstallation::query()->where('workspace_id', $project->workspace_id)->exists(),
                ];
            })(),
            // The repositories its apps come from, offered when turning previews on.
            'repos' => Inertia::optional(fn () => Previews::repos($project)),
            // Asked for when opening the promote dialog: what it would do.
            'promotion' => Inertia::optional(function () use ($request, $project, $environments) {
                $from = $project->environments->firstWhere('id', (int) $request->query('from'));
                $to = $project->environments->firstWhere('id', (int) $request->query('to'));

                return $from && $to && $from->id !== $to->id ? $environments->plan($from, $to) : [];
            }),
            // Asked for when opening an app's settings: its names, never values.
            'settings' => Inertia::optional(function () use ($request, $project, $settings) {
                $node = $project->nodes->firstWhere('id', (int) $request->query('app'));

                return $node?->isApp() ? $settings->read($node) : null;
            }),
        ]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $from = $project->name;
        $project->forceFill(['name' => trim($data['name'])])->save();
        Activity::record($workspace, 'project.rename', $project->name, meta: ['from' => $from]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);

        // The apps keep running as they are; only the network goes.
        $this->network->teardown($project, $request->user());
        Activity::record($workspace, 'project.delete', $project->name);
        $project->delete();

        return to_route('workspace.projects.index', $workspace);
    }

    /** Put a deploy that's already running on the canvas. */
    #[Audited]
    public function addNode(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'machine' => ['required', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'name' => ['required', 'string', 'max:63'],
            'environment' => ['nullable', 'integer'],
        ]);

        $environment = $this->actions->environment($project, $data['environment'] ?? null);
        $machine = $workspace->machines()->findOrFail((int) $data['machine']);
        $this->actions->addExisting($project, $request->user(), $machine, $data['name'], $environment);

        return back();
    }

    /**
     * Take something off the canvas. It keeps running where it is, unless
     * it's deleted as well: then its containers go from the machine (every
     * version of an app), and its data too if asked. A deploy that never
     * worked has nothing there, so deleting it only takes it off.
     */
    #[Audited]
    public function removeNode(Request $request, Workspace $workspace, Project $project, ProjectNode $node, DaemonJobQueue $queue): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($node->project_id === $project->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'delete' => ['nullable', 'boolean'],
            'delete_data' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'string'],
        ]);

        $containers = ($data['delete'] ?? false) ? Placement::containers($node->machine, $node->name) : [];
        if ($containers !== []) {
            if (($data['confirm'] ?? null) !== $node->name) {
                throw ValidationException::withMessages(['confirm' => 'Type its name to confirm.']);
            }
            if (($reason = $queue->unavailable($node->machine)) !== null) {
                throw ValidationException::withMessages(['confirm' => $reason]);
            }
            // Older versions first, so the data goes with the live one, last.
            usort($containers, fn (array $a, array $b) => AppReleases::retired($node->machine, $b) <=> AppReleases::retired($node->machine, $a));
            foreach ($containers as $i => $container) {
                $key = (string) $container['key'];
                $deleteData = $i === count($containers) - 1 && (bool) ($data['delete_data'] ?? false);
                $job = ['type' => 'service_remove', 'service' => $key, 'delete_data' => $deleteData, 'delete_adopted_volumes' => $deleteData && ($container['origin'] ?? null) !== 'created'];
                try {
                    ($container['details']['managed'] ?? null) === 'true'
                        ? $queue->queue($node->machine, $request->user(), 'service_remove', $job, $key)
                        : $queue->queue($node->machine, $request->user(), 'adopt', ['type' => 'adopt', 'service' => $key, 'dry_run' => false], $key,
                            then: ['kind' => 'service_remove', 'job' => $job, 'service' => $key]);
                } catch (RuntimeException $e) {
                    throw ValidationException::withMessages(['confirm' => $e->getMessage()]);
                }
            }
        }

        foreach ($project->links()->where(fn ($q) => $q->where('app_node_id', $node->id)->orWhere('resource_node_id', $node->id))->get() as $link) {
            // An app on its way off the machine isn't deployed again without them.
            if ($containers === [] || ! $node->isApp()) {
                $this->links->disconnect($link, $request->user());
            }
            $link->delete();
        }
        Activity::record($workspace, 'project.remove', "{$node->name} from {$project->name}", $node->machine);
        $node->delete();
        $this->network->sync($project, $request->user());

        return back();
    }

    /** Let an app use a resource: its settings go into the app. */
    #[Audited]
    public function link(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'app' => ['required', 'integer'],
            'resource' => ['required', 'integer'],
        ]);

        $app = $project->nodes()->findOrFail((int) $data['app']);
        $resource = $project->nodes()->findOrFail((int) $data['resource']);
        $this->actions->link($project, $request->user(), $app, $resource);

        return back();
    }

    #[Audited]
    public function unlink(Request $request, Workspace $workspace, Project $project, ProjectLink $link): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($link->project_id === $project->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $link->loadMissing(['app', 'resource']);
        Activity::record($workspace, 'project.unlink', "{$link->app->name} → {$link->resource->name}");
        $this->links->disconnect($link, $request->user());
        $link->delete();
        $this->network->sync($project, $request->user());

        return back();
    }

    /**
     * Add a database, cache, object storage or websockets from the toolbar:
     * deploy its template on the chosen machine and connect every app in
     * the project to it once it's up.
     */
    #[Audited]
    public function addResource(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Connections::DEFAULTS))],
            'machine' => ['required', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'environment' => ['nullable', 'integer'],
        ]);
        $environment = $this->actions->environment($project, $data['environment'] ?? null);
        $machine = $workspace->machines()->findOrFail((int) $data['machine']);
        $this->actions->addResource($project, $request->user(), $data['kind'], $machine, $environment);

        return back();
    }

    /**
     * A new environment: a copy of another (Staging from Production), its
     * parts deployed on the machines chosen for it, its databases filled
     * with the original's data if asked.
     */
    #[Audited]
    public function storeEnvironment(Request $request, Workspace $workspace, Project $project, Environments $environments): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('project_environments', 'name')->where('project_id', $project->id)],
            'source' => ['required', 'integer'],
            'machines' => ['array'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'copy_data' => ['boolean'],
        ]);

        $source = $this->actions->environment($project, $data['source']);
        $machines = [];
        foreach ((array) ($data['machines'] ?? []) as $from => $to) {
            $machines[(int) $from] = $workspace->machines()->findOrFail((int) $to);
        }

        // Every machine it lands on needs Docker, before anything is made.
        $targets = $source->nodes()->with('machine')->get()
            ->map(fn (ProjectNode $node) => $machines[$node->machine_id] ?? $node->machine)
            ->unique('id');
        foreach ($targets as $target) {
            DeployRequest::assertDocker($target, $request->user());
        }

        [$environment, $skipped] = $environments->create($project, $source, trim($data['name']), $machines, (bool) ($data['copy_data'] ?? false), $request->user());
        Activity::record($workspace, 'project.environment', "{$environment->name} in {$project->name}", meta: ['from' => $source->name]);

        return back()->with('project_notes', $skipped);
    }

    /** Rename an environment, or set how often its data is copied again. */
    #[Audited]
    public function updateEnvironment(Request $request, Workspace $workspace, Project $project, ProjectEnvironment $environment): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($environment->project_id === $project->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('project_environments', 'name')->where('project_id', $project->id)->ignore($environment->id)],
            'refresh' => ['sometimes', 'nullable', Rule::in(ProjectEnvironment::REFRESH)],
        ]);
        if (array_key_exists('refresh', $data) && $data['refresh'] !== null && $environment->source_id === null) {
            throw ValidationException::withMessages(['refresh' => 'Only a copy of another environment can have its data copied again.']);
        }

        $environment->forceFill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
        ]) + (array_key_exists('refresh', $data) ? ['refresh' => $data['refresh']] : []))->save();
        Activity::record($workspace, 'project.environment_update', "{$environment->name} in {$project->name}", meta: array_key_exists('refresh', $data) ? ['refresh' => $data['refresh'] ?? 'off'] : []);

        return back();
    }

    #[Audited]
    public function destroyEnvironment(Request $request, Workspace $workspace, Project $project, ProjectEnvironment $environment, Environments $environments): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($environment->project_id === $project->id, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($environment->id === $project->production()->id) {
            throw ValidationException::withMessages(['environment' => 'The first environment stays as long as the project does.']);
        }
        $data = $request->validate(['remove_services' => ['boolean']]);

        Activity::record($workspace, 'project.environment_delete', "{$environment->name} in {$project->name}", meta: ['services' => (bool) ($data['remove_services'] ?? false)]);
        $environments->remove($environment, (bool) ($data['remove_services'] ?? false), $request->user());

        return back();
    }

    /** Copy every database in an environment again from where it came from. */
    #[Audited]
    public function refreshEnvironment(Request $request, Workspace $workspace, Project $project, ProjectEnvironment $environment, ProjectData $data): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($environment->project_id === $project->id, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($environment->source_id === null) {
            throw ValidationException::withMessages(['environment' => 'This environment wasn’t copied from another, so there’s nothing to copy its data from.']);
        }

        $count = count($data->refresh($environment, $request->user()));
        Activity::record($workspace, 'project.refresh', "{$environment->name} in {$project->name}", meta: ['from' => $environment->source?->name]);
        if ($count === 0) {
            throw ValidationException::withMessages(['environment' => 'There’s no database in it to copy into.']);
        }

        return back();
    }

    /** Deploy each app in one environment at the code its copy in another runs. */
    #[Audited]
    public function promote(Request $request, Workspace $workspace, Project $project, Environments $environments): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['from' => ['required', 'integer'], 'to' => ['required', 'integer', 'different:from']]);

        $from = $this->actions->environment($project, $data['from']);
        $to = $this->actions->environment($project, $data['to']);
        if (! Environments::related($from, $to)) {
            throw ValidationException::withMessages(['to' => "{$from->name} wasn’t copied from {$to->name}, or the other way round, so their apps don’t pair up."]);
        }
        ['deployed' => $apps, 'blocked' => $blocked] = $environments->promote($from, $to, $request->user());
        if ($blocked !== []) {
            throw ValidationException::withMessages(['from' => 'Nothing was promoted. '.collect($blocked)->map(fn (string $why, string $app) => "{$app}: {$why}")->implode(' ')]);
        }
        if ($apps === []) {
            throw ValidationException::withMessages(['from' => 'Nothing to promote: every app is already at that code.']);
        }
        Activity::record($workspace, 'project.promote', "{$from->name} to {$to->name} in {$project->name}", meta: ['apps' => array_keys($apps)]);

        return back();
    }

    /** Change an app's settings and deploy it again with them. */
    #[Audited]
    public function updateSettings(Request $request, Workspace $workspace, Project $project, ProjectNode $node, AppSettings $settings): RedirectResponse
    {
        $this->owned($workspace, $project);
        abort_unless($node->project_id === $project->id && $node->isApp(), 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'set' => ['array', 'max:50'],
            'set.*' => ['nullable', 'string', 'max:10000'],
            'remove' => ['array', 'max:50'],
            'remove.*' => ['string', 'max:128'],
        ]);

        $set = array_map(fn ($value) => (string) $value, (array) ($data['set'] ?? []));
        $changed = $settings->write($node, $set, array_values(array_map('strval', (array) ($data['remove'] ?? []))), $request->user());
        if ($changed === []) {
            AuditContext::nothingChanged();
        } else {
            Activity::record($workspace, 'project.settings', $node->name, $node->machine, meta: ['names' => $changed]);
        }

        return back();
    }

    /**
     * Turn pull request previews on or off, or change where they run: each
     * pull request on the repository gets its own environment.
     */
    #[Audited]
    public function updatePreviews(Request $request, Workspace $workspace, Project $project, Previews $previews): RedirectResponse
    {
        $this->owned($workspace, $project);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'repo' => ['required_if:enabled,true', 'nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
            'machine' => ['required_if:enabled,true', 'nullable', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'copy_data' => ['boolean'],
            'domain' => ['nullable', 'string', 'max:200', 'regex:/^(?=.{4,200}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Previews::MAX],
        ], ['repo.regex' => 'Enter the repository as owner/name.', 'domain.regex' => 'Enter a domain like preview.example.com.']);

        if ($data['enabled'] && ! GitHubInstallation::query()->where('workspace_id', $workspace->id)->exists()) {
            throw ValidationException::withMessages(['repo' => 'Connect GitHub first, from Settings, so ServerOS hears about pull requests.']);
        }
        if ($data['enabled']) {
            DeployRequest::assertDocker($workspace->machines()->findOrFail((int) $data['machine']), $request->user());
        }

        // Off, or onto another repository: the open previews go.
        $before = Previews::settings($project);
        if ($before['enabled'] && (! $data['enabled'] || strtolower(trim((string) ($data['repo'] ?? ''))) !== $before['repo'])) {
            $previews->closeAll($project, $request->user());
        }

        $project->forceFill(['previews' => [
            'enabled' => (bool) $data['enabled'],
            'repo' => strtolower(trim((string) ($data['repo'] ?? ''))),
            'machine_id' => isset($data['machine']) ? (int) $data['machine'] : null,
            'copy_data' => (bool) ($data['copy_data'] ?? false),
            'domain' => filled($data['domain'] ?? null) ? strtolower(trim((string) $data['domain'])) : null,
            'limit' => (int) ($data['limit'] ?? Previews::DEFAULT_LIMIT),
            'user_id' => $request->user()->id,
        ]])->save();
        Activity::record($workspace, 'project.previews', $project->name, meta: ['enabled' => (bool) $data['enabled'], 'repo' => $data['repo'] ?? null]);

        return back();
    }

    private function owned(Workspace $workspace, Project $project): void
    {
        abort_unless($project->workspace_id === $workspace->id, 404);
    }

    /**
     * One word for the whole network: up when every machine has joined.
     *
     * @param  array<int, string>  $states
     */
    private static function networkState(array $states): string
    {
        if ($states === []) {
            return 'none';
        }
        if (in_array('failed', $states, true)) {
            return 'failed';
        }

        return collect($states)->every(fn ($state) => $state === 'up') ? 'up' : 'pending';
    }
}
