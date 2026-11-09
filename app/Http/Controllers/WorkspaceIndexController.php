<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaces = $request->user()->workspaces()
            ->withCount(['machines', 'members'])
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace) => [
                ...$workspace->toPageProps(),
                'url' => $workspace->url(),
                'switchUrl' => route('workspace.switch', ['workspace' => $workspace->slug]),
                'role' => $workspace->roleOf($request->user())?->label(),
                'machineCount' => $workspace->machines_count,
                'memberCount' => $workspace->members_count,
            ]);

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
        ]);
    }
}
