<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Jobs\DiagnoseFix;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Fixes\FixActions;
use App\Support\Fixes\FixAllowance;
use App\Support\Fixes\FixRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Fixes: the server agent's investigations, waiting ones first. Anyone in
 * the workspace can read them; starting one needs Pro and machine rights,
 * and approving one needs two-factor as well, like the console.
 */
class FixController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $fixes = AgentFix::query()->where('workspace_id', $workspace->id)->with('machine:id,name')
            ->latest()->limit(100)->get();
        $selected = $fixes->firstWhere('id', (int) $request->query('fix')) ?? $fixes->first();
        $user = $request->user();

        return Inertia::render('workspace/Fixes', [
            'workspace' => $workspace->toPageProps(),
            'fixes' => $fixes->map(fn (AgentFix $f) => [
                'id' => $f->id,
                'title' => $f->title ?? ($f->service_name ? "Looking into {$f->service_name}" : "Looking into {$f->machine->name}"),
                'state' => $f->state,
                'machine' => $f->machine->name,
                'service' => $f->service_name,
                'createdAt' => $f->created_at->toIso8601String(),
            ])->values(),
            'selected' => $selected === null ? null : $this->detail($selected->load(['user:id,name', 'decider:id,name'])),
            'machines' => $workspace->machines()->orderBy('name')->get()->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'services' => collect(is_array($m->inventory['services'] ?? null) ? $m->inventory['services'] : [])
                    ->filter(fn ($s) => is_array($s) && is_string($s['key'] ?? null))
                    ->map(fn ($s) => ['key' => $s['key'], 'name' => $s['name'] ?? $s['key'], 'status' => $s['status'] ?? null])
                    ->sortBy(fn ($s) => [$s['status'] === 'running' ? 1 : 0, $s['name']])->values()->all(),
            ])->values(),
            'can' => [
                'start' => $workspace->allows($user, WorkspacePermission::ManageMachines) && $workspace->hasAutomations(),
                'approve' => $workspace->allows($user, WorkspacePermission::ManageMachines),
            ],
            'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
            'planAllows' => $workspace->hasAutomations(),
            'allowed' => collect(FixActions::TYPES)->values(),
            // This hour's investigations, and when the next frees up once they're used.
            'allowance' => FixAllowance::for($workspace),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        if (! $workspace->hasAutomations()) {
            throw ValidationException::withMessages(['machine' => 'The server agent comes with Pro.']);
        }
        $data = $request->validate([
            'machine' => ['required', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'service' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (($why = FixAllowance::refuse($workspace)) !== null) {
            throw ValidationException::withMessages(['limit' => $why]);
        }

        $machine = $workspace->machines()->findOrFail((int) $data['machine']);
        $service = null;
        if (filled($data['service'] ?? null)) {
            $service = collect(is_array($machine->inventory['services'] ?? null) ? $machine->inventory['services'] : [])
                ->first(fn ($s) => is_array($s) && ($s['key'] ?? null) === $data['service']);
            if ($service === null) {
                throw ValidationException::withMessages(['service' => "That service isn’t on {$machine->name}."]);
            }
        }

        $fix = AgentFix::query()->create([
            'workspace_id' => $workspace->id,
            'machine_id' => $machine->id,
            'service_key' => $service['key'] ?? null,
            'service_name' => $service['name'] ?? null,
            'user_id' => $request->user()->id,
            'source' => 'manual',
            'note' => $data['note'] ?? null,
            'state' => 'investigating',
        ]);
        Activity::record($workspace, 'fix.start', $service['name'] ?? $machine->name, $machine, $request->user(), ['fix' => $fix->id]);
        DiagnoseFix::dispatch($fix->id);

        return to_route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fix->id]);
    }

    #[Audited]
    public function approve(Request $request, Workspace $workspace, AgentFix $fix, FixRunner $runner): RedirectResponse
    {
        $this->own($workspace, $fix);
        Gate::authorize('manageMachines', $workspace);
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['approve' => 'Turn on two-factor authentication to approve fixes: they act on your machines.']);
        }
        try {
            $runner->approve($fix->load('machine'), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['approve' => $e->getMessage()]);
        }

        return to_route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fix->id]);
    }

    #[Audited]
    public function decline(Request $request, Workspace $workspace, AgentFix $fix, FixRunner $runner): RedirectResponse
    {
        $this->own($workspace, $fix);
        Gate::authorize('manageMachines', $workspace);
        try {
            $runner->decline($fix->load('machine'), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['approve' => $e->getMessage()]);
        }

        return to_route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fix->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(AgentFix $fix): array
    {
        $evidence = $fix->evidence ?? [];

        return [
            'id' => $fix->id,
            'title' => $fix->title,
            'state' => $fix->state,
            'machine' => $fix->machine->name,
            'service' => $fix->service_name,
            'note' => $fix->note,
            'cause' => $fix->cause,
            'explanation' => $fix->explanation,
            'confidence' => $fix->confidence,
            'model' => $fix->model,
            'error' => $fix->error,
            'steps' => collect($fix->actions ?? [])->map(fn (array $a, int $i) => [
                ...$a,
                'label' => FixActions::TYPES[$a['type']] ?? $a['type'],
                'result' => ($fix->results ?? [])[$i] ?? null,
            ])->values(),
            'logs' => array_slice($evidence['logs'] ?? [], -30),
            'errors' => array_slice($evidence['errors'] ?? [], -10),
            'by' => $fix->user?->name,
            'decidedBy' => $fix->decider?->name,
            'createdAt' => $fix->created_at->toIso8601String(),
            'decidedAt' => $fix->decided_at?->toIso8601String(),
            'finishedAt' => $fix->finished_at?->toIso8601String(),
        ];
    }

    private function own(Workspace $workspace, AgentFix $fix): void
    {
        abort_unless($fix->workspace_id === $workspace->id, 404);
    }
}
