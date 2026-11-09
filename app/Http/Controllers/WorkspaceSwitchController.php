<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkspaceSwitchController extends Controller
{
    /**
     * Land in a workspace from the switcher: confirm the switch with a toast
     * (the session is shared across subdomains) and go to its home.
     */
    public function __invoke(Workspace $workspace): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => "Switched to {$workspace->name}"]);

        return redirect()->to($workspace->url());
    }
}
