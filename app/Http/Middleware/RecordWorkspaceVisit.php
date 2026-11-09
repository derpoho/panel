<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Stats\Visits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts a day of use when someone opens a page in their workspace. Staff
 * viewing a workspace as its owner don't count.
 */
class RecordWorkspaceVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $workspace = $request->route('workspace');
        $user = $request->user();
        if ($request->isMethod('GET') && $workspace instanceof Workspace && $user !== null
            && $response->isSuccessful() && ! Impersonation::active($request)) {
            Visits::record($workspace->id, $user->id);
        }

        return $response;
    }
}
