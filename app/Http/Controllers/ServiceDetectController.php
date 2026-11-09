<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServiceDetectController extends Controller
{
    /**
     * Look at a GitHub repository and say which template fits it.
     */
    #[NotAudited('Looks at a repository; nothing changes')]
    public function __invoke(Request $request, Workspace $workspace, RepositoryDetector $detector): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate(['repository' => ['required', 'string', 'max:300']]);

        return response()->json($detector->detect($data['repository'], $workspace));
    }
}
