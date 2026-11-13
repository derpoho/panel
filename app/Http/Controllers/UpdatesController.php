<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePackageUpdate;
use App\Models\PackageRollout;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Machines\PackageHolds;
use App\Support\Machines\PackageRollouts;
use App\Support\Machines\PackageUpdates;
use App\Support\Machines\StaleServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UpdatesController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $machines = $workspace->machines()->whereNotNull('uid')->orderBy('name')->get();
        $rows = MachinePackageUpdate::query()->whereIn('machine_id', $machines->pluck('id'))
            ->orderBy('name')->get(['machine_id', 'name', 'installed', 'candidate', 'security']);
        $checking = DaemonJob::query()
            ->whereIn('machine_id', $machines->pluck('id'))->whereIn('kind', PackageUpdates::KINDS)
            ->whereIn('state', ['queued', 'running'])->where('updated_at', '>=', now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES))
            ->pluck('machine_id')->unique()->values();

        return Inertia::render('workspace/Updates', [
            'workspace' => $workspace->toPageProps(),
            'summary' => self::summary($machines, $rows),
            'machines' => $machines->map(fn (Machine $machine) => self::machine($machine))->values()->all(),
            'packages' => self::packages($machines, $rows),
            'checking' => $checking->all(),
            'rollouts' => PackageRollout::query()->where('workspace_id', $workspace->id)->with('creator:id,name')
                ->latest('id')->limit(30)->get()->map(fn (PackageRollout $r) => PackageRollouts::summary($r))->all(),
            'groups' => $workspace->machineGroups()->orderBy('name')->get()
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name, 'machineIds' => $machines->where('machine_group_id', $group->id)->pluck('id')->values()->all()])
                ->filter(fn (array $group) => $group['machineIds'] !== [])->values()->all(),
            'commandsEnabled' => (bool) config('daemon.commands_enabled'),
            'view' => in_array($request->query('view'), ['packages', 'machines', 'rollouts'], true) ? $request->query('view') : null,
        ]);
    }

    #[Audited]
    public function check(Workspace $workspace, Request $request): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $queued = 0;
        $machines = $workspace->machines()->whereNotNull('uid')->get();
        foreach ($machines as $machine) {
            if ($machine->isOnline() && PackageUpdates::check($machine, $request->user()) !== null) {
                $queued++;
            }
        }
        if ($queued === 0) {
            $reason = $machines->isEmpty() ? 'No machines are connected yet.'
                : (config('daemon.commands_enabled') ? 'No machine could be checked: they’re offline, or busy with updates already.' : app(DaemonJobQueue::class)->unavailable($machines->first()));

            return response()->json(['error' => $reason], 409);
        }
        Activity::record($workspace, 'updates.check_all', null, null, $request->user(), ['machines' => $queued]);

        return response()->json(['queued' => $queued], 202);
    }

    #[Audited]
    public function storeRollout(Request $request, Workspace $workspace, PackageRollouts $rollouts): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $rollout = $rollouts->create($workspace, $request->user(), $request->all());

        return response()->json(['rollout' => PackageRollouts::summary($rollout)], 201);
    }

    public function showRollout(Workspace $workspace, PackageRollout $rollout): JsonResponse
    {
        abort_unless($rollout->workspace_id === $workspace->id, 404);

        return response()->json(['rollout' => PackageRollouts::detail($rollout)]);
    }

    #[Audited]
    public function cancelRollout(Request $request, Workspace $workspace, PackageRollout $rollout, PackageRollouts $rollouts): JsonResponse
    {
        abort_unless($rollout->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (! in_array($rollout->state, PackageRollout::OPEN, true)) {
            return response()->json(['error' => 'That rollout has already finished.'], 409);
        }
        $rollouts->cancel($rollout, $request->user());

        return response()->json(['rollout' => PackageRollouts::detail($rollout->fresh() ?? $rollout)]);
    }

    #[Audited]
    public function hold(Request $request, Workspace $workspace, PackageHolds $holds): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'packages' => ['required', 'array', 'min:1', 'max:200'],
            'packages.*' => ['string', 'max:200', 'regex:'.PackageUpdates::NAME_PATTERN],
            'machines' => ['required', 'array', 'min:1', 'max:2000'],
            'machines.*' => ['integer'],
            'hold' => ['required', 'boolean'],
        ]);

        $machines = $workspace->machines()->whereNotNull('uid')->whereIn('id', $data['machines'])->orderBy('name')->get();
        abort_if($machines->isEmpty(), 404);

        $result = $holds->apply($workspace, $request->user(), $machines, array_values(array_unique($data['packages'])), (bool) $data['hold']);

        return response()->json($result, $result['queued'] === [] ? 409 : 202);
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, MachinePackageUpdate>  $rows
     * @return array<string, mixed>
     */
    private static function summary(Collection $machines, Collection $rows): array
    {
        $checked = $machines->whereNotNull('updates_checked_at');

        return [
            'updates' => $rows->count(),
            'packages' => $rows->pluck('name')->unique()->count(),
            'security' => $rows->where('security', true)->count(),
            'machines' => $rows->pluck('machine_id')->unique()->count(),
            'total' => $machines->count(),
            'unchecked' => $machines->count() - $checked->count(),
            'rebootRequired' => $machines->filter(fn (Machine $m) => $m->needsReboot())->count(),
            'restartServices' => $machines->filter(fn (Machine $m) => StaleServices::count($m) > 0)->count(),
            'checkedAt' => $checked->min('updates_checked_at')?->toIso8601String(),
            'newestCheck' => $checked->max('updates_checked_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function machine(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'provider' => $machine->provider,
            'ip' => $machine->ip_address,
            'os' => PackageUpdates::osName($machine),
            'release' => PackageUpdates::releaseKey($machine),
            'online' => $machine->isOnline(),
            'groupId' => $machine->machine_group_id,
            'updates' => $machine->updates_count,
            'security' => $machine->security_updates_count,
            'rebootRequired' => $machine->needsReboot(),
            'staleServices' => StaleServices::count($machine),
            'checkedAt' => $machine->updates_checked_at?->toIso8601String(),
            'complete' => $machine->updates_complete,
            'held' => $machine->held_packages ?? [],
            'canVersions' => DaemonJobQueue::canInstallVersions($machine),
            'canPick' => MachineUpdatesController::canPick($machine),
        ];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, MachinePackageUpdate>  $rows
     * @return list<array<string, mixed>>
     */
    public static function packages(Collection $machines, Collection $rows): array
    {
        $byId = $machines->keyBy('id');
        $held = [];
        foreach ($machines as $machine) {
            foreach ($machine->held_packages ?? [] as $name) {
                $held[$name][] = $machine->id;
            }
        }

        $packages = $rows->groupBy('name')->map(function (Collection $group, string $name) use ($byId, $held) {
            $versions = $group->groupBy(fn (MachinePackageUpdate $row) => $row->candidate.'|'.PackageUpdates::releaseKey($byId[$row->machine_id]))
                ->map(fn (Collection $same) => [
                    'version' => $same->first()->candidate,
                    'release' => PackageUpdates::releaseKey($byId[$same->first()->machine_id]),
                    'os' => PackageUpdates::osName($byId[$same->first()->machine_id]),
                    'machineIds' => $same->pluck('machine_id')->unique()->values()->all(),
                ])
                ->sortByDesc(fn (array $v) => count($v['machineIds']))->values()->all();

            return [
                'name' => $name,
                'security' => $group->contains('security', true),
                'machineIds' => $group->pluck('machine_id')->unique()->values()->all(),
                'versions' => $versions,
                'installed' => $group->pluck('installed')->filter()->unique()->values()->all(),
                'heldOn' => $held[$name] ?? [],
            ];
        });

        foreach ($held as $name => $ids) {
            if (! $packages->has($name)) {
                $packages->put($name, ['name' => $name, 'security' => false, 'machineIds' => [], 'versions' => [], 'installed' => [], 'heldOn' => $ids]);
            }
        }

        return array_values($packages->sortBy([fn ($a, $b) => $b['security'] <=> $a['security'], fn ($a, $b) => count($b['machineIds']) <=> count($a['machineIds']), fn ($a, $b) => strcmp($a['name'], $b['name'])])->all());
    }
}
