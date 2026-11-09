<?php

namespace App\Http\Controllers;

use App\Support\WorkspaceSlugs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceAvailabilityController extends Controller
{
    /**
     * Check whether a workspace name is free, suggesting alternatives when it isn't.
     */
    public function __invoke(Request $request, WorkspaceSlugs $slugs): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $slug = strtolower(trim($request->string('slug')->toString()));
        $problem = $slugs->problemWith($slug);

        return response()->json([
            'slug' => $slug,
            'available' => $problem === null,
            'message' => $problem,
            'suggestions' => $problem === null ? [] : $slugs->suggest($slug)->all(),
        ]);
    }
}
