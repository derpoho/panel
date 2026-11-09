<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ServiceGroupController extends Controller
{
    /**
     * Group some of a machine's services under a name.
     */
    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        $this->authorizeFor($workspace, $machine);

        $group = $this->save($machine, new ServiceGroup, $data = $this->validated($request));
        Activity::record($workspace, 'service_group.create', $group->name, $machine, meta: ['services' => count($data['services'])]);

        return back();
    }

    /**
     * Rename a group or change what's in it.
     */
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, ServiceGroup $group): RedirectResponse
    {
        $this->authorizeFor($workspace, $machine, $group);

        $before = ['name' => $group->name, 'services' => $group->services];
        $data = $this->validated($request);
        $this->save($machine, $group, $data);

        $sorted = fn (array $list): array => collect($list)->sort()->values()->all();
        $renamed = $before['name'] !== $group->name;
        $moved = $sorted($before['services']) !== $sorted($data['services']);
        if ($renamed || $moved) {
            Activity::record($workspace, 'service_group.update', $group->name, $machine, meta: array_filter([
                'from' => $renamed ? $before['name'] : null,
                'services' => $moved ? count($data['services']) : null,
            ], fn ($v) => $v !== null));
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * Remove the group; its services go back to the main list.
     */
    #[Audited]
    public function destroy(Workspace $workspace, Machine $machine, ServiceGroup $group): RedirectResponse
    {
        $this->authorizeFor($workspace, $machine, $group);

        if ($group->icon_path !== null) {
            Storage::disk('public')->delete($group->icon_path);
        }
        $group->delete();
        Activity::record($workspace, 'service_group.delete', $group->name, $machine);

        return back();
    }

    /** Upload an icon for the group, replacing any it had. No SVGs: they can carry scripts. */
    #[NotAudited('Cosmetic')]
    public function updateIcon(Request $request, Workspace $workspace, Machine $machine, ServiceGroup $group): RedirectResponse
    {
        $this->authorizeFor($workspace, $machine, $group);
        $request->validate(['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);

        $previous = $group->icon_path;
        $group->forceFill(['icon_path' => $request->file('icon')->store("service-icons/{$machine->id}", 'public')])->save();
        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return back();
    }

    #[NotAudited('Cosmetic')]
    public function destroyIcon(Workspace $workspace, Machine $machine, ServiceGroup $group): RedirectResponse
    {
        $this->authorizeFor($workspace, $machine, $group);

        if ($group->icon_path !== null) {
            Storage::disk('public')->delete($group->icon_path);
            $group->forceFill(['icon_path' => null])->save();
        }

        return back();
    }

    private function authorizeFor(Workspace $workspace, Machine $machine, ?ServiceGroup $group = null): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if($group !== null && $group->machine_id !== $machine->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }

    /**
     * @return array{name: string, services: list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'services' => ['present', 'array', 'max:500'],
            'services.*' => ['string', 'max:200'],
        ]);

        return ['name' => trim($data['name']), 'services' => array_values(array_unique($data['services']))];
    }

    /**
     * @param  array{name: string, services: list<string>}  $data
     */
    private function save(Machine $machine, ServiceGroup $group, array $data): ServiceGroup
    {
        return ServiceGroup::put($machine, $group, $data['name'], $data['services']);
    }
}
