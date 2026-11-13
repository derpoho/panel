<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\SecurityController;
use App\Http\Requests\DeleteWorkspaceRequest;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Activity;
use App\Models\GitHubInstallation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\GitHub\GitHubApp;
use App\Support\Monitors\AlertKinds;
use App\Support\Updates\PanelUpdates;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkspaceSettingsController extends Controller
{
    public function general(Request $request, Workspace $workspace): Response
    {
        $canAlerts = $request->user()->can('manageMachines', $workspace);

        return Inertia::render('workspace/settings/General', [
            'workspace' => $workspace->toPageProps(),
            'canManageSettings' => $request->user()->can('manageSettings', $workspace),
            'canDelete' => $request->user()->can('delete', $workspace),
            'canManageAlerts' => $canAlerts,
            'alerts' => [
                'email' => (bool) ($workspace->alert_email ?? true),
                'webhook' => $canAlerts ? $workspace->alert_webhook_url : null,
                'webhookSet' => filled($workspace->alert_webhook_url),
                'muted' => AlertKinds::muted($workspace),
            ],
            'github' => $canAlerts && (GitHubApp::enabled() || Edition::selfHosted()) ? $workspace->githubInstallations()
                ->orderBy('account_login')
                ->get()
                ->map(fn (GitHubInstallation $installation) => [
                    'id' => $installation->id,
                    'account' => $installation->account_login,
                    'organisation' => $installation->account_type === 'Organization',
                    'manageUrl' => $installation->account_type === 'Organization'
                        ? 'https://github.com/organizations/'.rawurlencode($installation->account_login).'/settings/installations/'.$installation->installation_id
                        : 'https://github.com/settings/installations/'.$installation->installation_id,
                ])
                ->all() : null,
            'githubSetup' => Edition::selfHosted() && $canAlerts ? [
                'ready' => GitHubApp::enabled(),
                'canSetUp' => GitHubAppSetupController::canManage($request->user(), $workspace),
                'fromEnvironment' => GitHubApp::fromEnvironment(),
            ] : null,
            'panelUpdate' => fn () => Edition::installAdmin($request->user(), $workspace) ? PanelUpdates::props() : null,
        ]);
    }

    #[Audited]
    public function destroy(DeleteWorkspaceRequest $request, Workspace $workspace): SymfonyResponse
    {
        $name = $workspace->name;
        $logo = $workspace->logo_path;

        Activity::record($workspace, 'workspace.delete', $name);
        $workspace->delete();

        if ($logo !== null) {
            Storage::disk('public')->delete($logo);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted {$name}."]);

        return Inertia::location(rtrim((string) config('app.url'), '/').route('workspaces.index', absolute: false));
    }

    #[Audited]
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $before = $workspace->name;
        $workspace->update($request->validated());

        if ($workspace->wasChanged('name')) {
            Activity::record($workspace, 'workspace.rename', $workspace->name, meta: ['from' => $before]);
        } else {
            AuditContext::nothingChanged();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace updated.']);

        return back();
    }

    public function security(TwoFactorAuthenticationRequest $request, Workspace $workspace): Response
    {
        $canManage = $request->user()->can('manageSettings', $workspace);

        return Inertia::render('workspace/settings/Security', [
            ...SecurityController::props($request),
            'workspace' => $workspace->toPageProps(),
            'canManageSettings' => $canManage,
            'requireTwoFactor' => $workspace->require_two_factor,
            'emailDomains' => $canManage && $workspace->hasGoogleTeamAccess()
                ? WorkspaceEmailDomainController::props($workspace)
                : null,
            'googleSignIn' => $canManage && in_array('google', SocialAccount::enabled(), true)
                ? WorkspaceGoogleSignInController::props($request, $workspace)
                : null,
            'withoutTwoFactor' => $canManage
                ? $workspace->members()->withCount('passkeys')->orderBy('name')->get()
                    ->filter(fn (User $member) => $member->two_factor_confirmed_at === null && $member->passkeys_count === 0)
                    ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email])
                    ->values()
                : [],
            ...app(Extensions::class)->pagePropsFor('workspace/settings/Security', $request, $workspace),
        ]);
    }

    #[Audited]
    public function updateTwoFactor(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $require = $request->validate(['require' => ['required', 'boolean']])['require'];

        $user = $request->user();
        if ($require && ! (clone $workspace)->forceFill(['require_two_factor' => true])->satisfiesTwoFactor($user, $request)) {
            throw ValidationException::withMessages(['require' => $user->passkeys()->exists()
                ? 'Confirm it’s you with your passkey first, or set up two-factor sign-in on your own account.'
                : 'Set up two-factor sign-in or a passkey on your own account first.']);
        }

        $workspace->forceFill(['require_two_factor' => $require])->save();
        Activity::record($workspace, $require ? 'security.two_factor_on' : 'security.two_factor_off');

        Inertia::flash('toast', ['type' => 'success', 'message' => $require
            ? 'Two-factor sign-in is now required.'
            : 'Two-factor sign-in is no longer required.']);

        return back();
    }

    #[Audited]
    public function updateLogo(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ]);

        $previous = $workspace->logo_path;

        $workspace->forceFill([
            'logo_path' => $request->file('logo')->store('workspace-logos', 'public'),
        ])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }
        Activity::record($workspace, 'workspace.logo');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo updated.']);

        return back();
    }

    #[Audited]
    public function destroyLogo(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);

        if ($workspace->logo_path !== null) {
            Storage::disk('public')->delete($workspace->logo_path);
            $workspace->forceFill(['logo_path' => null])->save();
            Activity::record($workspace, 'workspace.logo_remove');
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    public function appearance(Workspace $workspace): Response
    {
        return Inertia::render('workspace/settings/Appearance', [
            'workspace' => $workspace->toPageProps(),
        ]);
    }

    public function profile(Request $request, Workspace $workspace): Response
    {
        $membership = $workspace->members()->withPivot(['role', 'alert_emails', 'weekly_summary'])->whereKey($request->user()->id)->first()?->pivot;

        return Inertia::render('workspace/settings/Profile', [
            'workspace' => $workspace->toPageProps(),
            'notifications' => [
                'alertEmails' => $membership->alert_emails ?? 'all',
                'weeklySummary' => (bool) ($membership->weekly_summary ?? true),
                'getsAlerts' => in_array($membership->role ?? null, ['owner', 'admin'], true),
            ],
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'deleteAccount' => [
                'hasPassword' => $request->user()->password_set_at !== null,
                'email' => $request->user()->email,
                'goes' => $request->user()->ownedWorkspaces()
                    ->whereDoesntHave('members', fn ($q) => $q->whereKeyNot($request->user()->id))
                    ->orderBy('name')->pluck('name')->all(),
                'blocking' => ProfileDeleteRequest::sharedWorkspaces($request->user()),
                'support' => (string) config('mail.support.address'),
            ],
        ]);
    }

    #[NotAudited('Personal: what you’re emailed; changes nothing for the team')]
    public function updateNotifications(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'alert_emails' => ['required', Rule::in(['all', 'problems', 'none'])],
            'weekly_summary' => ['required', 'boolean'],
        ]);

        $workspace->members()->updateExistingPivot($request->user()->id, [
            'alert_emails' => $data['alert_emails'],
            'weekly_summary' => $data['weekly_summary'],
        ]);

        return back();
    }
}
