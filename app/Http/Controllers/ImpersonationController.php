<?php

namespace App\Http\Controllers;

use App\Support\Admin\Impersonation;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves "View as": back to the admin's own account, on the page of the
 * person they were viewing as.
 */
class ImpersonationController extends Controller
{
    #[Audited]
    public function __invoke(Request $request): Response
    {
        $viewed = $request->user();
        $admin = Impersonation::stop($request);

        if ($admin === null) {
            // Not viewing as anyone, or that admin lost their access since
            // (stop() has closed that session): just a sign-out.
            AuditContext::nothingChanged();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Inertia::location(rtrim((string) config('app.url'), '/').'/login');
        }

        // A full page load: this may have come from a workspace subdomain.
        return Inertia::location(rtrim((string) config('app.url'), '/')."/admin/users/{$viewed->id}");
    }
}
