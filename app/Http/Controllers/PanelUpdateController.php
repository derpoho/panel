<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Edition;
use App\Support\Updates\PanelUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class PanelUpdateController extends Controller
{
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless(Edition::installAdmin($request->user(), $workspace), 404);

        return response()->json(PanelUpdates::props());
    }

    #[NotAudited('Only asks GitHub for the newest release')]
    public function check(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(Edition::installAdmin($request->user(), $workspace), 404);

        $check = PanelUpdates::check();
        $available = PanelUpdates::available();
        Inertia::flash('toast', match (true) {
            ! $check['reached'] => ['type' => 'warning', 'message' => __('Couldn’t reach :host to check for updates.', ['host' => (string) parse_url((string) config('serveros.updates.feed_url'), PHP_URL_HOST)])],
            $available !== null => ['type' => 'success', 'message' => __('ServerOS :version is out.', ['version' => $available['version']])],
            default => ['type' => 'success', 'message' => __('ServerOS is up to date.')],
        });

        return back();
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(Edition::installAdmin($request->user(), $workspace), 404);

        try {
            $update = PanelUpdates::request($workspace, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['update' => $e->getMessage()]);
        }

        Activity::record($workspace, 'panel.update', $update->version, meta: ['from' => $update->from_version]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Updating to :version. It starts within a minute.', ['version' => $update->version])]);

        return back();
    }
}
