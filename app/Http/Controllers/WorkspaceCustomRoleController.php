<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * A Business workspace's own roles: a name and the permissions it grants.
 * Deleting one moves its members back to their base role (Viewer).
 */
class WorkspaceCustomRoleController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $this->validated($request, $workspace);
        $role = $workspace->customRoles()->create($data);
        Activity::record($workspace, 'role.create', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added the {$role->name} role."]);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, WorkspaceCustomRole $role): RedirectResponse
    {
        abort_unless($role->workspace_id === $workspace->id, 404);
        $role->update($this->validated($request, $workspace, $role));
        Activity::record($workspace, 'role.update', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved the {$role->name} role."]);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, WorkspaceCustomRole $role): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($role->workspace_id === $workspace->id, 404);

        $role->delete();
        Activity::record($workspace, 'role.delete', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted the {$role->name} role. Its members are Viewers now."]);

        return back();
    }

    /**
     * @return array{name: string, description: string|null, permissions: list<string>}
     */
    private function validated(Request $request, Workspace $workspace, ?WorkspaceCustomRole $role = null): array
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($workspace->hasCustomRoles(), 403, 'Custom roles are on Business.');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::notIn(['Owner', 'Admin', 'Member', 'Viewer', 'owner', 'admin', 'member', 'viewer']),
                Rule::unique('workspace_custom_roles')->where('workspace_id', $workspace->id)->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(WorkspacePermission::class)],
        ], [
            'name.not_in' => 'That name is taken by a built-in role.',
            'name.unique' => 'There’s already a role with that name.',
        ]);

        return [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'permissions' => array_values(array_unique($data['permissions'])),
        ];
    }
}
