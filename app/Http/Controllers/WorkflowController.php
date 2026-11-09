<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Workflows\ReleaseSpec;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowInputs;
use App\Support\Workflows\WorkflowSchedule;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Workflows: a trigger and steps drawn on a canvas, run by ServerOS.
 */
class WorkflowController extends Controller
{
    public function index(Workspace $workspace): Response
    {
        $workflows = $workspace->workflows()->orderBy('name')->get();
        $runs = WorkflowRun::query()
            ->whereIn('workflow_id', $workflows->modelKeys())
            ->where('started_at', '>=', now()->subDays(7))
            ->get(['id', 'workflow_id', 'state', 'started_at'])
            ->groupBy('workflow_id');

        return Inertia::render('workspace/Workflows', [
            'workspace' => $workspace->toPageProps(),
            'workflows' => $workflows->map(function (Workflow $w) use ($runs) {
                $recent = $runs[$w->id] ?? collect();
                $last = $recent->sortByDesc('id')->first();

                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'description' => $w->description,
                    'enabled' => $w->enabled,
                    'trigger' => $w->trigger,
                    'rules' => (object) $w->triggerRules(),
                    'steps' => max(0, count($w->graph['nodes'] ?? []) - 1),
                    'lastRun' => $last ? ['state' => $last->state, 'at' => $last->started_at->toIso8601String()] : null,
                    'lastRunAt' => $w->last_run_at?->toIso8601String(),
                    'runs7d' => $recent->count(),
                    'failed7d' => $recent->where('state', 'failed')->count(),
                    'nextRunAt' => $w->enabled ? $w->next_run_at?->toIso8601String() : null,
                ];
            })->values(),
            'templates' => WorkflowTemplates::list(),
            'planAllows' => $workspace->hasAutomations(),
            // Shown behind the upgrade card: what a workflow looks like here.
            'preview' => $workspace->hasAutomations() ? null : [
                'graph' => WorkflowTemplates::build('ci-deploy', $workspace)['graph'],
                'options' => WorkflowInputs::options($workspace),
            ],
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        if (! $workspace->hasAutomations()) {
            throw ValidationException::withMessages(['template' => 'Workflows come with Pro. Choose Pro or Business to use them.']);
        }
        if ($workspace->workflows()->count() >= 100) {
            throw ValidationException::withMessages(['template' => 'A workspace can have 100 workflows.']);
        }

        $key = $request->validate(['template' => ['required', Rule::in(array_column(WorkflowTemplates::list(), 'key'))]])['template'];
        $template = WorkflowTemplates::build($key, $workspace);

        $workflow = $workspace->workflows()->create([
            'name' => $template['name'],
            'graph' => $template['graph'],
            'trigger' => (string) (((array) (collect($template['graph']['nodes'])->firstWhere('kind', 'trigger')['rules'] ?? []))['event'] ?? 'manual'),
            'run_as' => $request->user()->id,
            'enabled' => false,
        ]);
        if ($workflow->trigger === 'webhook') {
            $workflow->rotateWebhook();
            $workflow->save();
        }
        Activity::record($workspace, 'workflow.add', $workflow->name);

        return to_route('workspace.workflows.show', ['workspace' => $workspace->slug, 'workflow' => $workflow->id]);
    }

    public function show(Request $request, Workspace $workspace, Workflow $workflow): Response
    {
        $this->own($workspace, $workflow);
        $canManage = Gate::allows('manageMachines', $workspace);
        $runId = $request->integer('run') ?: null;
        $run = $runId ? $workflow->runs()->find($runId) : null;

        return Inertia::render('workspace/WorkflowEditor', [
            'workspace' => $workspace->toPageProps(),
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'enabled' => $workflow->enabled,
                'trigger' => $workflow->trigger,
                'graph' => $workflow->graph,
                'webhookUrl' => $canManage ? $workflow->webhookUrl() : null,
                'nextRunAt' => $workflow->enabled ? $workflow->next_run_at?->toIso8601String() : null,
                'runAs' => $workflow->runAs?->name,
                'updatedAt' => $workflow->updated_at?->toIso8601String(),
            ],
            'problems' => (object) WorkflowSteps::problems(WorkflowSteps::clean($workflow->graph), $workspace),
            'options' => WorkflowInputs::options($workspace),
            'runs' => fn () => $workflow->runs()->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn (WorkflowRun $r) => [
                    'id' => $r->id,
                    'state' => $r->state,
                    'trigger' => $r->trigger['summary'] ?? '',
                    'kind' => $r->trigger['kind'] ?? 'manual',
                    'user' => $r->startedByName(),
                    'startedAt' => $r->started_at->toIso8601String(),
                    'seconds' => (int) $r->started_at->diffInSeconds($r->finished_at ?? now()),
                ])->values(),
            'run' => fn () => $run ? [...$run->toPageProps(), 'graph' => $run->graph] : null,
            'canManage' => $canManage,
            'planAllows' => $workspace->hasAutomations(),
            'twoFactor' => $request->user()->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Workflow $workflow): RedirectResponse
    {
        $this->own($workspace, $workflow);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:280'],
            'enabled' => ['required', 'boolean'],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['present', 'array', 'max:'.WorkflowSteps::MAX_NODES],
            'graph.edges' => ['present', 'array', 'max:'.WorkflowSteps::MAX_EDGES],
        ]);

        $graph = WorkflowSteps::clean($data['graph']);
        $problems = WorkflowSteps::problems($graph, $workspace);
        if ($data['enabled'] && ! $workspace->hasAutomations()) {
            throw ValidationException::withMessages(['enabled' => 'Workflows come with Pro. Choose Pro or Business to turn one on.']);
        }
        if ($data['enabled'] && $problems !== []) {
            throw ValidationException::withMessages(['enabled' => 'Fix the steps marked with a warning before turning it on.']);
        }
        if (WorkflowSteps::hasCommand($graph) && ! $request->user()->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['graph' => 'Workflows that run commands run them as you, so turn on two-factor authentication first.']);
        }

        $rules = (array) (collect($graph['nodes'])->firstWhere('kind', 'trigger')['rules'] ?? []);
        $event = (string) ($rules['event'] ?? 'manual');
        $wasEnabled = $workflow->enabled;

        $workflow->fill([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'enabled' => $data['enabled'],
            'graph' => $graph,
            'trigger' => $event,
            'run_as' => $request->user()->id,
            'next_run_at' => $event === 'schedule' ? WorkflowSchedule::next($rules) : null,
        ]);
        if ($event === 'webhook' && $workflow->webhook_token === null) {
            $workflow->rotateWebhook();
        }
        $workflow->save();

        Activity::record($workspace, match (true) {
            $data['enabled'] && ! $wasEnabled => 'workflow.enable',
            ! $data['enabled'] && $wasEnabled => 'workflow.disable',
            default => 'workflow.update',
        }, $workflow->name);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, Workflow $workflow): RedirectResponse
    {
        $this->own($workspace, $workflow);
        Gate::authorize('manageMachines', $workspace);

        Activity::record($workspace, 'workflow.remove', $workflow->name);
        $workflow->delete();

        return to_route('workspace.workflows.index', ['workspace' => $workspace->slug]);
    }

    /**
     * Run it now, by hand. Works whether or not it's turned on, so it can be
     * tried before it is.
     */
    #[Audited]
    public function run(Request $request, Workspace $workspace, Workflow $workflow, WorkflowEngine $engine): RedirectResponse
    {
        $this->own($workspace, $workflow);
        Gate::authorize('manageMachines', $workspace);
        if (! $workspace->hasAutomations()) {
            throw ValidationException::withMessages(['run' => 'Workflows come with Pro.']);
        }
        $problems = WorkflowSteps::problems(WorkflowSteps::clean($workflow->graph), $workspace);
        if ($problems !== []) {
            throw ValidationException::withMessages(['run' => 'Fix the steps marked with a warning first.']);
        }

        $data = WorkflowInputs::sampleData($workflow);
        $by = AuditContext::viewAsSession($request->user()) === null ? $request->user()->name : AuditContext::SUPPORT;
        $run = $engine->start($workflow, 'manual', "Run by {$by}", $data, $request->user());
        if ($run === null) {
            throw ValidationException::withMessages(['run' => 'It has run '.Workflow::RUNS_PER_HOUR.' times in the last hour; try again later.']);
        }

        return to_route('workspace.workflows.show', ['workspace' => $workspace->slug, 'workflow' => $workflow->id, 'run' => $run->id]);
    }

    #[Audited]
    public function cancel(Request $request, Workspace $workspace, Workflow $workflow, WorkflowRun $run, WorkflowEngine $engine): RedirectResponse
    {
        $this->own($workspace, $workflow);
        abort_unless($run->workflow_id === $workflow->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $engine->cancel($run, $request->user());

        return back();
    }

    /**
     * Switch an app a run released back to the release it replaced: still
     * kept beside it, so it's a switch rather than a build.
     */
    #[Audited]
    public function rollback(Request $request, Workspace $workspace, Workflow $workflow, WorkflowRun $run, string $node, DaemonJobQueue $queue): RedirectResponse
    {
        $this->own($workspace, $workflow);
        abort_unless($run->workflow_id === $workflow->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $step = $run->steps[$node] ?? null;
        $rules = (array) (collect($run->graph['nodes'] ?? [])->firstWhere('id', $node)['rules'] ?? []);
        $commit = is_array($step) ? ($step['rollbackTo'] ?? null) : null;
        $machine = $workspace->machines()->find((int) ($rules['machine_id'] ?? 0));
        abort_if(! is_string($commit) || $machine === null || ($step['rolledBack'] ?? false), 404);
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['rollback' => 'Turn on two-factor authentication to roll back releases.']);
        }
        if (($reason = $queue->unavailable($machine, 'release')) !== null) {
            throw ValidationException::withMessages(['rollback' => $reason]);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'release', ReleaseSpec::job($workspace, $rules, $commit), (string) $rules['service'], WorkflowEngine::JOB_TIMEOUT_MINUTES * 60 - 60);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['rollback' => $e->getMessage()]);
        }
        $run->steps = [...$run->steps, $node => [...$step, 'rolledBack' => true]];
        $run->save();

        return to_route('workspace.machines.show', ['workspace' => $workspace->slug, 'machine' => $machine->id, 'job' => $job->id]);
    }

    #[Audited]
    public function rotateWebhook(Workspace $workspace, Workflow $workflow): RedirectResponse
    {
        $this->own($workspace, $workflow);
        Gate::authorize('manageMachines', $workspace);

        $workflow->rotateWebhook();
        $workflow->save();
        Activity::record($workspace, 'workflow.webhook', $workflow->name);

        return back();
    }

    /**
     * The incoming webhook: anything that can POST can start the workflow,
     * and its JSON is the trigger's data ({{data.ref}}, say).
     */
    public function hook(Request $request, string $token, WorkflowEngine $engine): JsonResponse
    {
        $workflow = Workflow::query()->with('workspace')->where('webhook_hash', hash('sha256', $token))->first();
        if ($workflow === null || ! hash_equals((string) $workflow->webhook_token, $token)) {
            return response()->json(['error' => 'Unknown webhook.'], 404);
        }
        if (! $workflow->enabled || $workflow->trigger !== 'webhook') {
            return response()->json(['error' => 'This workflow is turned off.'], 409);
        }

        $data = $request->isJson() ? $request->json()->all() : $request->except([]);
        $data = array_slice($data, 0, 50, true);
        $encoded = json_encode($data) ?: '';
        if (strlen($encoded) > 16_000) {
            $data = ['_truncated' => true];
        }

        $run = $engine->start($workflow, 'webhook', 'Webhook from '.($request->ip() ?? 'unknown'), $data);
        if ($run === null) {
            return response()->json(['error' => 'It has run too often in the last hour.'], 429);
        }

        return response()->json(['run' => $run->id, 'state' => $run->state], 202);
    }

    private function own(Workspace $workspace, Workflow $workflow): void
    {
        abort_unless($workflow->workspace_id === $workspace->id, 404);
    }
}
