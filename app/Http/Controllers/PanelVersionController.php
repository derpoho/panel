<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The build the panel is serving now, as Inertia versions its pages. An open
 * dashboard compares it with the one it loaded, to offer a reload once a new
 * release is live.
 */
class PanelVersionController extends Controller
{
    /** The workspace is taken so the route's view check gets the model. */
    public function __invoke(Request $request, Workspace $workspace, HandleInertiaRequests $inertia): JsonResponse
    {
        return response()->json(['version' => $inertia->version($request)])
            ->header('Cache-Control', 'no-store');
    }
}
