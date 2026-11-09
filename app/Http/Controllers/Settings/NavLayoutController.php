<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\NotAudited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NavLayoutController extends Controller
{
    /** The dashboard's sections along the top, or in a sidebar. */
    public const LAYOUTS = ['top', 'sidebar'];

    /**
     * Save how the dashboard lays out its navigation. Kept on the account
     * rather than the browser, so it follows people to every workspace's
     * subdomain and to their other devices.
     */
    #[NotAudited('Personal: where your own dashboard shows its navigation')]
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'layout' => ['required', 'string', Rule::in(self::LAYOUTS)],
        ]);

        $request->user()
            ->forceFill(['nav_layout' => $validated['layout'] === 'top' ? null : $validated['layout']])
            ->save();

        return back();
    }
}
