<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Auth\EmailDomainVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The mail domains a workspace has proven are its company's, with a DNS
 * TXT record. Company Google sign-in, single sign-on and SCIM all go by
 * them. Adding and checking one is on Pro and Business; removing one
 * always works, so a workspace that leaves its plan can.
 */
class WorkspaceEmailDomainController extends Controller
{
    public function __construct(private readonly EmailDomainVerifier $verifier) {}

    /**
     * What the Security page shows managers about them.
     *
     * @return array{available: bool, domains: array<int, array<string, mixed>>}
     */
    public static function props(Workspace $workspace): array
    {
        return [
            'available' => $workspace->hasGoogleTeamAccess(),
            'domains' => $workspace->emailDomains()->get()->map->toPageProps()->values()->all(),
        ];
    }

    /** Add a domain to verify. */
    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $request->validate(['domain' => ['required', 'string', 'max:255']]);

        $domain = EmailDomainVerifier::normalize((string) $request->input('domain'));
        if ($domain === null) {
            throw ValidationException::withMessages(['domain' => 'That doesn’t look like a domain. Try something like acme.com.']);
        }
        if (($problem = EmailDomainVerifier::problemWith($workspace, $domain)) !== null) {
            throw ValidationException::withMessages(['domain' => $problem]);
        }

        $workspace->emailDomains()->create(['domain' => $domain]);
        Activity::record($workspace, 'email_domain.add', $domain);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$domain}. Add the TXT record, then check it."]);

        return back();
    }

    /** Look for the TXT record now. */
    #[Audited]
    public function check(Workspace $workspace, WorkspaceEmailDomain $emailDomain): RedirectResponse
    {
        $this->authorizeChange($workspace);
        abort_unless($emailDomain->workspace_id === $workspace->id, 404);

        $result = $this->verifier->check($emailDomain);
        if (! AuditContext::recorded()) {
            AuditContext::nothingChanged();
        }

        Inertia::flash('toast', match ($result) {
            'verified' => ['type' => 'success', 'message' => "{$emailDomain->domain} is verified."],
            'taken' => ['type' => 'error', 'message' => "Another workspace has already verified {$emailDomain->domain}."],
            'no-answer' => ['type' => 'error', 'message' => 'DNS didn’t answer. Try again in a minute.'],
            default => ['type' => 'error', 'message' => 'No TXT record yet. DNS changes can take a few minutes to show up.'],
        });

        return back();
    }

    /** Stop using a domain. */
    #[Audited]
    public function destroy(Workspace $workspace, WorkspaceEmailDomain $emailDomain): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($emailDomain->workspace_id === $workspace->id, 404);

        if ($workspace->require_google_sign_in && $workspace->emailDomains()->count() === 1) {
            throw ValidationException::withMessages(['domain' => 'Turn off Require Google sign-in first. Without a domain, only the owner could get in.']);
        }
        if ($workspace->require_sso && $emailDomain->isVerified() && $workspace->emailDomains()->whereNotNull('verified_at')->count() === 1) {
            throw ValidationException::withMessages(['domain' => 'Turn off Require SSO first. Without a verified domain, only the owner could get in.']);
        }

        $emailDomain->delete();
        Activity::record($workspace, 'email_domain.remove', $emailDomain->domain);

        return back();
    }

    private function authorizeChange(Workspace $workspace): void
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $workspace->hasGoogleTeamAccess()) {
            throw ValidationException::withMessages(['domain' => 'Verified domains are on Pro and Business.']);
        }
    }
}
