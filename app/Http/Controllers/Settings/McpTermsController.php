<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AccountEvent;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class McpTermsController extends Controller
{
    /**
     * Record that the user accepted the experimental MCP terms. The first
     * acceptance is kept; accepting again changes nothing.
     */
    #[Audited]
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['accepted' => ['accepted']]);

        $user = $request->user();

        if ($user->mcp_terms_accepted_at === null) {
            $user->forceFill(['mcp_terms_accepted_at' => now()])->save();
            AccountEvent::record($user, 'mcp_terms.accepted');
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }
}
