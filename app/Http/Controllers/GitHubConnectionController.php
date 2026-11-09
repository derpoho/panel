<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\GitHubInstallation;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\GitHub\GitHubApp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting GitHub to a workspace, for private repositories: off to
 * GitHub to install the ServerOS app, and back to /github/setup on the
 * main domain (one fixed address, whichever workspace started it).
 */
class GitHubConnectionController extends Controller
{
    /** How long someone has to finish installing on GitHub. */
    private const STATE_TTL_SECONDS = 3600;

    public function __construct(private readonly GitHubApp $github) {}

    public function connect(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless(GitHubApp::enabled(), 404);

        $state = Crypt::encryptString((string) json_encode([
            'workspace' => $workspace->id,
            'user' => $request->user()->id,
            'at' => now()->getTimestamp(),
        ]));
        // GitHub passes state back, but with sign-in during install it may
        // not: the session (shared across subdomains) remembers it too.
        $request->session()->put('github_connect_state', $state);

        return Inertia::location($this->github->installUrl($state));
    }

    /**
     * GitHub's redirect after installing (or changing which repositories
     * the app can read). The installation id in the URL is only trusted
     * once the person's own GitHub account can see it.
     */
    public function setup(Request $request): RedirectResponse
    {
        abort_unless(GitHubApp::enabled(), 404);

        $workspace = $this->workspaceFromState($request);
        if ($workspace === null) {
            return redirect()->route('workspaces.index')->with('status', 'Connect GitHub from a workspace’s settings, so ServerOS knows which workspace it’s for.');
        }
        $back = $workspace->url().'/settings/general';

        // An organisation member asked an owner to approve it.
        if ($request->query('setup_action') === 'request') {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your organisation’s owners to approve. Once they do, connect GitHub again.']);

            return redirect()->away($back);
        }

        $installationId = (int) $request->query('installation_id');
        $code = (string) $request->query('code');
        $details = $installationId > 0 && $code !== '' && $this->github->userCanSee($code, $installationId)
            ? $this->github->installation($installationId)
            : null;
        $login = is_array($details) ? ($details['account']['login'] ?? null) : null;
        if (! is_string($login)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'GitHub didn’t confirm that installation. Try connecting again.']);

            return redirect()->away($back);
        }

        $workspace->githubInstallations()->updateOrCreate(['installation_id' => $installationId], [
            'account_login' => $login,
            'account_type' => is_string($details['account']['type'] ?? null) ? $details['account']['type'] : null,
            'connected_by' => $request->user()->id,
        ]);
        Activity::record($workspace, 'github.connected', $login);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected GitHub ({$login}). Its private repositories you shared can be deployed now."]);

        return redirect()->away($back);
    }

    #[Audited]
    public function destroy(Workspace $workspace, GitHubInstallation $installation): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($installation->workspace_id === $workspace->id, 404);

        $installation->delete();
        Activity::record($workspace, 'github.disconnected', $installation->account_login);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Disconnected {$installation->account_login}. To take the app off GitHub too, uninstall it in GitHub’s settings."]);

        return back();
    }

    /** The workspace the install was started from, if it's still valid. */
    private function workspaceFromState(Request $request): ?Workspace
    {
        try {
            $state = json_decode(Crypt::decryptString((string) ($request->query('state') ?: $request->session()->pull('github_connect_state'))), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($state) || ! is_int($state['workspace'] ?? null)
            || ($state['user'] ?? null) !== $request->user()?->id
            || now()->getTimestamp() - (int) ($state['at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return null;
        }

        $workspace = Workspace::query()->find($state['workspace']);

        return $workspace !== null && $request->user()->can('manageMachines', $workspace) ? $workspace : null;
    }
}
