<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\CommunityLicenseController;
use App\Http\Controllers\ConsoleLockController;
use App\Http\Controllers\DaemonConnectController;
use App\Http\Controllers\DaemonInstallScriptController;
use App\Http\Controllers\DaemonJobController;
use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\DeploySourceController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\FixController;
use App\Http\Controllers\GitHubAppSetupController;
use App\Http\Controllers\GitHubConnectionController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\MachineDataController;
use App\Http\Controllers\MachineDeployController;
use App\Http\Controllers\MachineDiskUsageController;
use App\Http\Controllers\MachineFileController;
use App\Http\Controllers\MachineFirewallController;
use App\Http\Controllers\MachineGroupController;
use App\Http\Controllers\MachineMetadataController;
use App\Http\Controllers\MachineScanController;
use App\Http\Controllers\MachineServiceController;
use App\Http\Controllers\MachineSettingsController;
use App\Http\Controllers\MachineTunnelController;
use App\Http\Controllers\MachineUpdatesController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\Mcp\OAuthAuthorizeController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PanelVersionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\RobotsTxtController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceDetectController;
use App\Http\Controllers\ServiceGroupController;
use App\Http\Controllers\StatusIncidentController;
use App\Http\Controllers\StatusSubscriptionController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\UpdatesController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkspaceAiController;
use App\Http\Controllers\WorkspaceApiController;
use App\Http\Controllers\WorkspaceAvailabilityController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceCustomRoleController;
use App\Http\Controllers\WorkspaceDomainController;
use App\Http\Controllers\WorkspaceEmailDomainController;
use App\Http\Controllers\WorkspaceGoogleSignInController;
use App\Http\Controllers\WorkspaceHomeController;
use App\Http\Controllers\WorkspaceIndexController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceJoinRequestController;
use App\Http\Controllers\WorkspaceLogStoreController;
use App\Http\Controllers\WorkspaceMachinePolicyController;
use App\Http\Controllers\WorkspaceMcpController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceOnboardingController;
use App\Http\Controllers\WorkspacePinController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Controllers\WorkspaceStatusPageController;
use App\Http\Controllers\WorkspaceSwitchController;
use App\Http\Controllers\WorkspaceWelcomeController;
use App\Http\Middleware\RecordWorkspaceVisit;
use App\Http\Middleware\RequirePasswordIfSet;
use App\Http\Middleware\RequireWorkspaceGoogleSignIn;
use App\Http\Middleware\RequireWorkspaceTwoFactor;
use App\Http\Middleware\SendToCustomDomain;
use App\Http\Middleware\StaffTwoFactor;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\Licensing\CommunityLicense;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->whereIn('provider', SocialAccount::PROVIDERS)->name('oauth.redirect');
    Route::post('auth/{provider}/credential', [SocialLoginController::class, 'credential'])->whereIn('provider', ['google'])->name('oauth.credential');
});
Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->middleware('throttle:20,1')->whereIn('provider', SocialAccount::PROVIDERS)->name('oauth.callback');

app(Extensions::class)->loadRoutes(Extensions::SIGN_IN);

app(Extensions::class)->loadRoutes(Extensions::SUBDOMAINS);

Route::get('_domain/handoff/{token}', [WorkspaceDomainController::class, 'handoff'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('domains.handoff');

Route::domain('{workspace:slug}.'.config('app.domain'))
    ->middleware(['auth', 'verified', 'can:view,workspace', RequireWorkspaceGoogleSignIn::class, ...app(Extensions::class)->middleware(Extensions::WORKSPACE_SIGN_IN), RequireWorkspaceTwoFactor::class, ...app(Extensions::class)->middleware(Extensions::WORKSPACE), RecordWorkspaceVisit::class, SendToCustomDomain::class])
    ->group(function () {
        Route::get('/', WorkspaceHomeController::class)->name('workspace.home');
        Route::post('issues/dismiss', [WorkspaceHomeController::class, 'dismiss'])->name('workspace.issues.dismiss');
        Route::get('switch', WorkspaceSwitchController::class)->name('workspace.switch');
        Route::get('panel-version', PanelVersionController::class)->middleware('throttle:30,1')->name('workspace.panel-version');
        Route::post('onboarding/dismiss', [WorkspaceOnboardingController::class, 'dismiss'])->name('workspace.onboarding.dismiss');
        Route::delete('onboarding/dismiss', [WorkspaceOnboardingController::class, 'resume'])->name('workspace.onboarding.resume');
        Route::get('welcome', [WorkspaceWelcomeController::class, 'show'])->name('workspace.welcome.show');
        Route::post('welcome/about', [WorkspaceWelcomeController::class, 'about'])->name('workspace.welcome.about');
        Route::post('welcome/control', [WorkspaceWelcomeController::class, 'control'])->name('workspace.welcome.control');
        Route::post('welcome/plan', [WorkspaceWelcomeController::class, 'plan'])->name('workspace.welcome.plan');
        Route::post('welcome/card', [WorkspaceWelcomeController::class, 'card'])->middleware('throttle:10,1')->name('workspace.welcome.card');
        Route::post('welcome/finish', [WorkspaceWelcomeController::class, 'finish'])->name('workspace.welcome.finish');
        Route::post('pins/{pinned:slug}', [WorkspacePinController::class, 'store'])->withoutScopedBindings()->name('workspace.pins.store');
        Route::delete('pins/{pinned:slug}', [WorkspacePinController::class, 'destroy'])->withoutScopedBindings()->name('workspace.pins.destroy');
        Route::get('machines', [MachineController::class, 'index'])->name('workspace.machines.index');
        Route::post('machine-groups', [MachineGroupController::class, 'store'])->name('workspace.machine-groups.store');
        Route::put('machine-groups/{group}', [MachineGroupController::class, 'update'])->withoutScopedBindings()->name('workspace.machine-groups.update');
        Route::delete('machine-groups/{group}', [MachineGroupController::class, 'destroy'])->withoutScopedBindings()->name('workspace.machine-groups.destroy');
        Route::post('machines/{machine}/service-groups', [ServiceGroupController::class, 'store'])->name('workspace.service-groups.store');
        Route::put('machines/{machine}/service-groups/{group}', [ServiceGroupController::class, 'update'])->withoutScopedBindings()->name('workspace.service-groups.update');
        Route::delete('machines/{machine}/service-groups/{group}', [ServiceGroupController::class, 'destroy'])->withoutScopedBindings()->name('workspace.service-groups.destroy');
        Route::post('machines/{machine}/service-groups/{group}/icon', [ServiceGroupController::class, 'updateIcon'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.service-groups.icon.update');
        Route::delete('machines/{machine}/service-groups/{group}/icon', [ServiceGroupController::class, 'destroyIcon'])->withoutScopedBindings()->name('workspace.service-groups.icon.destroy');
        Route::get('machines/{machine}', [MachineController::class, 'show'])->name('workspace.machines.show');
        Route::get('machines/{machine}/{tab}', [MachineController::class, 'show'])->whereIn('tab', ['services', 'scheduled', 'usage', 'ports', 'files', 'specs', 'deployments']);
        Route::delete('machines/{machine}', [MachineController::class, 'destroy'])->name('workspace.machines.destroy');
        Route::get('machines/{machine}/settings', [MachineSettingsController::class, 'show'])->name('workspace.machines.settings');
        Route::get('machines/{machine}/settings/{tab}', [MachineSettingsController::class, 'show'])->whereIn('tab', ['general', 'status']);
        Route::patch('machines/{machine}', [MachineSettingsController::class, 'update'])->name('workspace.machines.update');
        Route::put('machines/{machine}/status-page', [MachineSettingsController::class, 'updateStatusPage'])->name('workspace.machines.status-page.update');
        Route::post('machines/{machine}/status-page/{kind}', [MachineSettingsController::class, 'updateStatusImage'])->whereIn('kind', ['icon', 'favicon'])->middleware('throttle:20,1')->name('workspace.machines.status-page.image.update');
        Route::delete('machines/{machine}/status-page/{kind}', [MachineSettingsController::class, 'destroyStatusImage'])->whereIn('kind', ['icon', 'favicon'])->name('workspace.machines.status-page.image.destroy');
        Route::put('machines/{machine}/policy', [MachineSettingsController::class, 'updatePolicy'])->name('workspace.machines.policy.update');
        Route::post('machines/{machine}/daemon-update', [MachineSettingsController::class, 'installUpdate'])->middleware('throttle:10,1')->name('workspace.machines.daemon-update');
        Route::put('machines/{machine}/log-skip', [MachineSettingsController::class, 'updateLogSkip'])->name('workspace.machines.log-skip.update');
        Route::post('machines/metadata', [MachineMetadataController::class, 'bulk'])->middleware('throttle:30,1')->name('workspace.machines.metadata.bulk');
        Route::post('machines/collected-data', [MachineDataController::class, 'bulk'])->middleware('throttle:10,1')->name('workspace.machines.collected-data.bulk');
        Route::post('machines/{machine}/collected-data', [MachineDataController::class, 'clear'])->middleware('throttle:10,1')->name('workspace.machines.collected-data.clear');
        Route::post('machines/{machine}/collected-data/skipped-services', [MachineDataController::class, 'clearSkipped'])->middleware('throttle:10,1')->name('workspace.machines.collected-data.skipped');
        Route::post('machines/{machine}/icon', [MachineSettingsController::class, 'updateIcon'])->middleware('throttle:20,1')->name('workspace.machines.icon.update');
        Route::delete('machines/{machine}/icon', [MachineSettingsController::class, 'destroyIcon'])->name('workspace.machines.icon.destroy');
        Route::get('backups', [BackupController::class, 'index'])->name('workspace.backups.index');
        Route::get('backups/{tab}', [BackupController::class, 'index'])->whereIn('tab', ['machines', 'destinations', 'history']);
        Route::put('machines/{machine}/backups', [BackupController::class, 'update'])->name('workspace.machines.backups.update');
        Route::post('machines/{machine}/backups/run', [BackupController::class, 'run'])->middleware('throttle:10,1')->name('workspace.machines.backups.run');
        Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.backups.restore');
        Route::post('backup-destinations', [BackupDestinationController::class, 'store'])->middleware('throttle:20,1')->name('workspace.backup-destinations.store');
        Route::put('backup-destinations/{destination}', [BackupDestinationController::class, 'update'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.backup-destinations.update');
        Route::post('backup-destinations/{destination}/retry', [BackupDestinationController::class, 'retry'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.backup-destinations.retry');
        Route::delete('backup-destinations/{destination}', [BackupDestinationController::class, 'destroy'])->withoutScopedBindings()->name('workspace.backup-destinations.destroy');
        Route::get('services', [ServiceController::class, 'index'])->name('workspace.services.index');
        Route::get('projects', [ProjectController::class, 'index'])->name('workspace.projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('workspace.projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->withoutScopedBindings()->name('workspace.projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->withoutScopedBindings()->name('workspace.projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->withoutScopedBindings()->name('workspace.projects.destroy');
        Route::post('projects/{project}/nodes', [ProjectController::class, 'addNode'])->withoutScopedBindings()->name('workspace.projects.nodes.store');
        Route::delete('projects/{project}/nodes/{node}', [ProjectController::class, 'removeNode'])->withoutScopedBindings()->name('workspace.projects.nodes.destroy');
        Route::post('projects/{project}/links', [ProjectController::class, 'link'])->withoutScopedBindings()->name('workspace.projects.links.store');
        Route::delete('projects/{project}/links/{link}', [ProjectController::class, 'unlink'])->withoutScopedBindings()->name('workspace.projects.links.destroy');
        Route::post('projects/{project}/resources', [ProjectController::class, 'addResource'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.projects.resources.store');
        Route::post('projects/{project}/environments', [ProjectController::class, 'storeEnvironment'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.projects.environments.store');
        Route::patch('projects/{project}/environments/{environment}', [ProjectController::class, 'updateEnvironment'])->withoutScopedBindings()->name('workspace.projects.environments.update');
        Route::delete('projects/{project}/environments/{environment}', [ProjectController::class, 'destroyEnvironment'])->withoutScopedBindings()->name('workspace.projects.environments.destroy');
        Route::post('projects/{project}/environments/{environment}/refresh', [ProjectController::class, 'refreshEnvironment'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.projects.environments.refresh');
        Route::patch('projects/{project}/previews', [ProjectController::class, 'updatePreviews'])->withoutScopedBindings()->name('workspace.projects.previews');
        Route::post('projects/{project}/promote', [ProjectController::class, 'promote'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.projects.promote');
        Route::patch('projects/{project}/nodes/{node}/settings', [ProjectController::class, 'updateSettings'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.projects.nodes.settings');
        Route::get('logs', [LogController::class, 'index'])->name('workspace.logs.index');
        Route::get('activity', [ActivityController::class, 'index'])->name('workspace.activity.index');
        Route::get('activity/export', [ActivityController::class, 'export'])->name('workspace.activity.export');
        Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('workspace.search');
        Route::post('machines/{machine}/maintenance', [MaintenanceController::class, 'start'])->middleware('throttle:20,1')->name('workspace.machines.maintenance.start');
        Route::delete('machines/{machine}/maintenance', [MaintenanceController::class, 'end'])->name('workspace.machines.maintenance.end');
        Route::post('machines/{machine}/maintenance/scheduled', [MaintenanceController::class, 'schedule'])->middleware('throttle:20,1')->name('workspace.machines.maintenance.schedule');
        Route::delete('machines/{machine}/maintenance/scheduled/{window}', [MaintenanceController::class, 'cancel'])->withoutScopedBindings()->name('workspace.machines.maintenance.cancel');
        Route::get('machines/{machine}/maintenance/quiet-time', [MaintenanceController::class, 'suggest'])->middleware('throttle:30,1')->name('workspace.machines.maintenance.suggest');
        Route::post('monitors/{monitor}/mute', [MaintenanceController::class, 'mute'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.monitors.mute');
        Route::delete('monitors/{monitor}/mute', [MaintenanceController::class, 'unmute'])->withoutScopedBindings()->name('workspace.monitors.unmute');
        Route::get('fixes', [FixController::class, 'index'])->name('workspace.fixes.index');
        Route::post('fixes', [FixController::class, 'store'])->middleware('throttle:30,1')->name('workspace.fixes.store');
        Route::post('fixes/{fix}/approve', [FixController::class, 'approve'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.fixes.approve');
        Route::post('fixes/{fix}/decline', [FixController::class, 'decline'])->withoutScopedBindings()->name('workspace.fixes.decline');
        Route::get('workflows', [WorkflowController::class, 'index'])->name('workspace.workflows.index');
        Route::post('workflows', [WorkflowController::class, 'store'])->middleware('throttle:20,1')->name('workspace.workflows.store');
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->withoutScopedBindings()->name('workspace.workflows.show');
        Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->withoutScopedBindings()->middleware('throttle:60,1')->name('workspace.workflows.update');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->withoutScopedBindings()->name('workspace.workflows.destroy');
        Route::post('workflows/{workflow}/run', [WorkflowController::class, 'run'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.workflows.run');
        Route::post('workflows/{workflow}/runs/{run}/rollback/{node}', [WorkflowController::class, 'rollback'])->withoutScopedBindings()->middleware('throttle:10,1')->where('node', '[A-Za-z0-9_-]{1,64}')->name('workspace.workflows.rollback');
        Route::post('workflows/{workflow}/runs/{run}/cancel', [WorkflowController::class, 'cancel'])->withoutScopedBindings()->name('workspace.workflows.cancel');
        Route::post('workflows/{workflow}/webhook', [WorkflowController::class, 'rotateWebhook'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.workflows.webhook');
        Route::get('monitors', [MonitorController::class, 'index'])->name('workspace.monitors.index');
        Route::post('monitors', [MonitorController::class, 'store'])->middleware('throttle:30,1')->name('workspace.monitors.store');
        Route::put('monitor-alerts', [MonitorController::class, 'updateAlerts'])->middleware('throttle:20,1')->name('workspace.monitors.alerts.update');
        Route::post('monitor-alerts/test', [MonitorController::class, 'testAlert'])->middleware('throttle:10,1')->name('workspace.monitors.alerts.test');
        Route::get('monitors/{monitor}', [MonitorController::class, 'show'])->withoutScopedBindings()->name('workspace.monitors.show');
        Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->withoutScopedBindings()->name('workspace.monitors.update');
        Route::patch('monitors/{monitor}/pause', [MonitorController::class, 'pause'])->withoutScopedBindings()->name('workspace.monitors.pause');
        Route::post('monitors/{monitor}/check', [MonitorController::class, 'check'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.monitors.check');
        Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->withoutScopedBindings()->name('workspace.monitors.destroy');
        Route::get('logs/feed', [LogController::class, 'feed'])->middleware('throttle:240,1')->name('workspace.logs.feed');
        Route::get('logs/deletion', [LogController::class, 'deletion'])->middleware('throttle:30,1')->name('workspace.logs.deletion');
        Route::delete('logs', [LogController::class, 'destroy'])->middleware('throttle:10,1')->name('workspace.logs.destroy');
        Route::post('services/detect', ServiceDetectController::class)->middleware('throttle:20,1')->name('workspace.services.detect');
        Route::post('machines/{machine}/sources', [DeploySourceController::class, 'start'])->middleware('throttle:20,1')->name('workspace.sources.start');
        Route::post('machines/{machine}/sources/{token}/chunk', [DeploySourceController::class, 'chunk'])->middleware('throttle:600,1')->name('workspace.sources.chunk');
        Route::post('machines/{machine}/sources/{token}/finish', [DeploySourceController::class, 'finish'])->middleware('throttle:20,1')->name('workspace.sources.finish');
        Route::post('machines/{machine}/apps/{service}/versions', [AppVersionController::class, 'store'])->middleware('throttle:20,1')->name('workspace.apps.versions.store');
        Route::post('machines/{machine}/apps/{service}/domain', [AppVersionController::class, 'domain'])->middleware('throttle:10,1')->name('workspace.apps.domain');
        Route::post('machines/{machine}/apps/{service}/rollback', [AppVersionController::class, 'rollback'])->middleware('throttle:10,1')->name('workspace.apps.rollback');
        Route::put('machines/{machine}/apps/{service}/settings', [AppVersionController::class, 'settings'])->middleware('throttle:20,1')->name('workspace.apps.settings');
        Route::get('machines/{machine}/apps/{service}/variables', [AppVersionController::class, 'variables'])->middleware('throttle:60,1')->name('workspace.apps.variables');
        Route::put('machines/{machine}/apps/{service}/variables', [AppVersionController::class, 'updateVariables'])->middleware('throttle:20,1')->name('workspace.apps.variables.update');
        Route::post('machines/{machine}/deploy', MachineDeployController::class)->middleware('throttle:10,1')->name('workspace.machines.deploy');
        Route::get('machines/{machine}/deploy/taken', [MachineDeployController::class, 'taken'])->middleware('throttle:60,1')->name('workspace.machines.deploy.taken');
        Route::post('machines/{machine}/domain-check', DomainCheckController::class)->middleware('throttle:30,1')->name('workspace.machines.domain-check');
        Route::post('console/unlock', [ConsoleLockController::class, 'unlock'])->middleware('throttle:5,1')->name('workspace.console.unlock');
        Route::post('console/lock', [ConsoleLockController::class, 'lock'])->name('workspace.console.lock');
        Route::post('machines/{machine}/files', [MachineFileController::class, 'store'])->middleware('throttle:120,1')->name('workspace.machines.files.store');
        Route::get('machines/{machine}/files/{job}', [MachineFileController::class, 'show'])->middleware('throttle:600,1')->name('workspace.machines.files.show');
        Route::post('assistant', AssistantController::class)->middleware('throttle:20,1')->name('workspace.assistant');
        Route::post('assistant/decide', [AssistantController::class, 'decide'])->middleware('throttle:30,1')->name('workspace.assistant.decide');
        Route::get('assistant/models', [AssistantController::class, 'models'])->middleware('throttle:30,1')->name('workspace.assistant.models');
        Route::put('machines/{machine}/hidden-services', [MachineServiceController::class, 'hide'])
            ->middleware('throttle:30,1')
            ->name('workspace.machines.services.hide');
        Route::put('machines/{machine}/services/{service}/logs', [MachineServiceController::class, 'muteLogs'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->middleware('throttle:30,1')
            ->name('workspace.machines.services.logs');
        Route::get('machines/{machine}/services/{service}', [MachineServiceController::class, 'show'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->name('workspace.machines.services.show');
        Route::get('machines/{machine}/services/{service}/{tab}', [MachineServiceController::class, 'show'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->whereIn('tab', ['overview', 'logs', 'console', 'files', 'details']);
        Route::post('machines/{machine}/services/{service}/icon', [MachineServiceController::class, 'updateIcon'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->middleware('throttle:20,1')
            ->name('workspace.machines.services.icon.update');
        Route::delete('machines/{machine}/services/{service}/icon', [MachineServiceController::class, 'destroyIcon'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->name('workspace.machines.services.icon.destroy');
        Route::put('machines/{machine}/services/{service}/label', [MachineServiceController::class, 'rename'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->name('workspace.machines.services.rename');
        Route::post('machines/{machine}/services/{service}/actions', [MachineServiceController::class, 'action'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->middleware('throttle:30,1')
            ->name('workspace.machines.services.action');
        Route::post('machines/{machine}/services/{service}/clone', [MachineServiceController::class, 'clone'])
            ->where('service', '[A-Za-z0-9:._@-]+')
            ->middleware('throttle:10,1')
            ->name('workspace.machines.services.clone');
        Route::post('machines/{machine}/scan', [MachineScanController::class, 'store'])->middleware('throttle:10,1')->name('workspace.machines.scan.store');
        Route::post('machines/{machine}/refresh', [MachineScanController::class, 'discover'])->middleware('throttle:20,1')->name('workspace.machines.refresh');
        Route::post('machines/{machine}/disk-usage', [MachineDiskUsageController::class, 'store'])->middleware('throttle:10,1')->name('workspace.machines.disk-usage.store');
        Route::get('machines/{machine}/disk-usage/{job}', [MachineDiskUsageController::class, 'show'])->name('workspace.machines.disk-usage.show');
        Route::post('machines/{machine}/updates/check', [MachineUpdatesController::class, 'check'])->middleware('throttle:10,1')->name('workspace.machines.updates.check');
        Route::post('machines/{machine}/updates', [MachineUpdatesController::class, 'install'])->middleware('throttle:5,1')->name('workspace.machines.updates.install');
        Route::post('machines/{machine}/updates/restart', [MachineUpdatesController::class, 'restart'])->middleware('throttle:5,1')->name('workspace.machines.updates.restart');
        Route::get('machines/{machine}/updates', [MachineUpdatesController::class, 'snapshot'])->name('workspace.machines.updates.snapshot');
        Route::get('machines/{machine}/updates/{job}', [MachineUpdatesController::class, 'show'])->name('workspace.machines.updates.show');
        Route::get('updates', [UpdatesController::class, 'index'])->name('workspace.updates.index');
        Route::post('updates/check', [UpdatesController::class, 'check'])->middleware('throttle:6,1')->name('workspace.updates.check');
        Route::post('updates/holds', [UpdatesController::class, 'hold'])->middleware('throttle:20,1')->name('workspace.updates.holds');
        Route::post('updates/rollouts', [UpdatesController::class, 'storeRollout'])->middleware('throttle:10,1')->name('workspace.updates.rollouts.store');
        Route::get('updates/rollouts/{rollout}', [UpdatesController::class, 'showRollout'])->withoutScopedBindings()->name('workspace.updates.rollouts.show');
        Route::delete('updates/rollouts/{rollout}', [UpdatesController::class, 'cancelRollout'])->withoutScopedBindings()->name('workspace.updates.rollouts.cancel');
        Route::post('machines/{machine}/firewall', [MachineFirewallController::class, 'store'])->middleware('throttle:20,1')->name('workspace.machines.firewall.store');
        Route::get('machines/{machine}/firewall/{job}', [MachineFirewallController::class, 'show'])->name('workspace.machines.firewall.show');
        Route::post('machines/{machine}/tunnel', [MachineTunnelController::class, 'store'])->middleware('throttle:10,1')->name('workspace.machines.tunnel.store');
        Route::delete('machines/{machine}/tunnel', [MachineTunnelController::class, 'destroy'])->middleware('throttle:10,1')->name('workspace.machines.tunnel.destroy');
        Route::get('machines/{machine}/tunnel/{job}', [MachineTunnelController::class, 'show'])->name('workspace.machines.tunnel.show');
        Route::post('machines/{machine}/reboot', [MachineUpdatesController::class, 'reboot'])->middleware('throttle:3,1')->name('workspace.machines.reboot');
        Route::get('machines/{machine}/scan', [MachineScanController::class, 'show'])->name('workspace.machines.scan.show');
        Route::post('machines/{machine}/jobs/{job}/retry', [DaemonJobController::class, 'retry'])->middleware('throttle:10,1')->name('workspace.machines.jobs.retry');
        Route::post('machines/{machine}/jobs/{job}/abandon', [DaemonJobController::class, 'abandon'])->name('workspace.machines.jobs.abandon');
        Route::get('machines/connect/options', [DaemonConnectController::class, 'options'])->middleware('throttle:60,1')->name('workspace.machines.connect.options');
        Route::post('machines/connect', [DaemonConnectController::class, 'store'])->middleware('throttle:10,1')->name('workspace.machines.connect');
        Route::get('machines/connect/{enrolment}/status', [DaemonConnectController::class, 'status'])->middleware('throttle:60,1')->name('workspace.machines.connect.status');

        Route::get('settings', fn (Workspace $workspace) => to_route('workspace.settings.general', $workspace->slug))
            ->name('workspace.settings');
        Route::get('settings/general', [WorkspaceSettingsController::class, 'general'])->name('workspace.settings.general');
        Route::get('settings/github/connect', [GitHubConnectionController::class, 'connect'])->middleware('throttle:10,1')->name('workspace.github.connect');
        Route::get('settings/github/app', [GitHubAppSetupController::class, 'show'])->name('workspace.github.app');
        Route::put('settings/github/app', [GitHubAppSetupController::class, 'store'])->middleware('throttle:10,1')->name('workspace.github.app.store');
        Route::delete('settings/github/app', [GitHubAppSetupController::class, 'destroy'])->name('workspace.github.app.destroy');
        Route::delete('settings/github/{installation}', [GitHubConnectionController::class, 'destroy'])->withoutScopedBindings()->name('workspace.github.destroy');
        Route::patch('settings/general', [WorkspaceSettingsController::class, 'update'])->name('workspace.settings.update');
        Route::delete('settings/workspace', [WorkspaceSettingsController::class, 'destroy'])->name('workspace.settings.destroy');
        Route::post('settings/logo', [WorkspaceSettingsController::class, 'updateLogo'])->name('workspace.settings.logo.update');
        Route::delete('settings/logo', [WorkspaceSettingsController::class, 'destroyLogo'])->name('workspace.settings.logo.destroy');
        Route::get('settings/profile', [WorkspaceSettingsController::class, 'profile'])->name('workspace.settings.profile');
        Route::get('settings/appearance', [WorkspaceSettingsController::class, 'appearance'])->name('workspace.settings.appearance');
        Route::put('settings/notifications', [WorkspaceSettingsController::class, 'updateNotifications'])->middleware('throttle:30,1')->name('workspace.settings.notifications');

        Route::get('settings/members', [WorkspaceMemberController::class, 'index'])->name('workspace.members.index');
        Route::patch('settings/members/{member}', [WorkspaceMemberController::class, 'update'])->name('workspace.members.update');
        Route::delete('settings/members/{member}', [WorkspaceMemberController::class, 'destroy'])->name('workspace.members.destroy');
        Route::post('settings/roles', [WorkspaceCustomRoleController::class, 'store'])->name('workspace.roles.store');
        Route::put('settings/roles/{role}', [WorkspaceCustomRoleController::class, 'update'])->name('workspace.roles.update');
        Route::delete('settings/roles/{role}', [WorkspaceCustomRoleController::class, 'destroy'])->name('workspace.roles.destroy');
        Route::get('settings/domains', [WorkspaceDomainController::class, 'show'])->name('workspace.settings.domains');
        Route::post('settings/domains', [WorkspaceDomainController::class, 'store'])->middleware('throttle:10,1')->name('workspace.settings.domains.store');
        Route::post('settings/domains/{domain}/check', [WorkspaceDomainController::class, 'check'])->withoutScopedBindings()->middleware('throttle:20,1')->name('workspace.settings.domains.check');
        Route::delete('settings/domains/{domain}', [WorkspaceDomainController::class, 'destroy'])->withoutScopedBindings()->name('workspace.settings.domains.destroy');
        Route::get('domains/go', [WorkspaceDomainController::class, 'go'])->middleware('throttle:30,1')->name('workspace.domains.go');
        Route::get('settings/api', [WorkspaceApiController::class, 'show'])->name('workspace.settings.api');
        Route::post('settings/api/keys', [WorkspaceApiController::class, 'store'])->middleware('throttle:20,1')->name('workspace.settings.api.keys.store');
        Route::delete('settings/api/keys/{key}', [WorkspaceApiController::class, 'destroy'])->withoutScopedBindings()->name('workspace.settings.api.keys.destroy');
        Route::get('settings/mcp', [WorkspaceMcpController::class, 'show'])->name('workspace.settings.mcp');
        Route::put('settings/ai/{provider}', [WorkspaceAiController::class, 'update'])->middleware('throttle:10,1')->whereIn('provider', ['anthropic', 'openai'])->name('workspace.settings.ai.update');
        Route::delete('settings/ai/{provider}', [WorkspaceAiController::class, 'destroy'])->whereIn('provider', ['anthropic', 'openai'])->name('workspace.settings.ai.destroy');
        Route::post('settings/mcp/keys', [WorkspaceMcpController::class, 'store'])->middleware('throttle:20,1')->name('workspace.settings.mcp.keys.store');
        Route::delete('settings/mcp/connections/{token}', [WorkspaceMcpController::class, 'destroy'])->name('workspace.settings.mcp.connections.destroy');
        Route::get('settings/machine-policies', [WorkspaceMachinePolicyController::class, 'show'])->name('workspace.settings.machine-policies');
        Route::post('settings/machine-policies', [WorkspaceMachinePolicyController::class, 'store'])->middleware('throttle:30,1')->name('workspace.settings.machine-policies.store');
        Route::put('settings/machine-policies/{policy}', [WorkspaceMachinePolicyController::class, 'update'])->withoutScopedBindings()->middleware('throttle:30,1')->name('workspace.settings.machine-policies.update');
        Route::post('settings/machine-policies/{policy}/default', [WorkspaceMachinePolicyController::class, 'makeDefault'])->withoutScopedBindings()->name('workspace.settings.machine-policies.default');
        Route::post('settings/machine-policies/{policy}/install-updates', [WorkspaceMachinePolicyController::class, 'installUpdates'])->withoutScopedBindings()->middleware('throttle:10,1')->name('workspace.settings.machine-policies.install-updates');
        Route::delete('settings/machine-policies/{policy}', [WorkspaceMachinePolicyController::class, 'destroy'])->withoutScopedBindings()->name('workspace.settings.machine-policies.destroy');
        Route::get('settings/status-page', [WorkspaceStatusPageController::class, 'show'])->name('workspace.settings.status-page');
        Route::put('settings/status-page', [WorkspaceStatusPageController::class, 'update'])->name('workspace.settings.status-page.update');
        Route::post('settings/status-page/{kind}', [WorkspaceStatusPageController::class, 'updateImage'])->whereIn('kind', ['icon', 'favicon'])->middleware('throttle:20,1')->name('workspace.settings.status-page.image.update');
        Route::delete('settings/status-page/{kind}', [WorkspaceStatusPageController::class, 'destroyImage'])->whereIn('kind', ['icon', 'favicon'])->name('workspace.settings.status-page.image.destroy');
        Route::get('settings/log-storage', [WorkspaceLogStoreController::class, 'show'])->name('workspace.settings.log-storage');
        Route::put('settings/log-storage', [WorkspaceLogStoreController::class, 'update'])->middleware('throttle:20,1')->name('workspace.settings.log-storage.update');
        Route::post('settings/log-storage/test', [WorkspaceLogStoreController::class, 'test'])->middleware('throttle:10,1')->name('workspace.settings.log-storage.test');
        Route::post('settings/log-storage/enable', [WorkspaceLogStoreController::class, 'enable'])->middleware('throttle:10,1')->name('workspace.settings.log-storage.enable');
        Route::post('settings/log-storage/disable', [WorkspaceLogStoreController::class, 'disable'])->name('workspace.settings.log-storage.disable');
        Route::delete('settings/log-storage', [WorkspaceLogStoreController::class, 'destroy'])->name('workspace.settings.log-storage.destroy');
        Route::post('status-incidents', [StatusIncidentController::class, 'store'])->middleware('throttle:30,1')->name('workspace.status-incidents.store');
        Route::post('status-incidents/{incident}/updates', [StatusIncidentController::class, 'update'])->withoutScopedBindings()->middleware('throttle:60,1')->name('workspace.status-incidents.update');
        Route::delete('status-incidents/{incident}', [StatusIncidentController::class, 'destroy'])->withoutScopedBindings()->name('workspace.status-incidents.destroy');
        Route::get('settings/security', [WorkspaceSettingsController::class, 'security'])
            ->middleware(RequirePasswordIfSet::class)
            ->name('workspace.settings.security');
        Route::put('settings/two-factor', [WorkspaceSettingsController::class, 'updateTwoFactor'])->name('workspace.settings.two-factor');
        Route::post('settings/email-domains', [WorkspaceEmailDomainController::class, 'store'])->middleware('throttle:20,1')->name('workspace.settings.email-domains.store');
        Route::post('settings/email-domains/{emailDomain}/check', [WorkspaceEmailDomainController::class, 'check'])->middleware('throttle:20,1')->name('workspace.settings.email-domains.check');
        Route::delete('settings/email-domains/{emailDomain}', [WorkspaceEmailDomainController::class, 'destroy'])->name('workspace.settings.email-domains.destroy');
        Route::put('settings/domain-join', [WorkspaceGoogleSignInController::class, 'updateJoin'])->name('workspace.settings.domain-join');
        Route::put('settings/require-google', [WorkspaceGoogleSignInController::class, 'updateRequire'])->name('workspace.settings.require-google');
        Route::post('sign-in/google', [WorkspaceGoogleSignInController::class, 'signInAgain'])->middleware('throttle:10,1')->name('workspace.google-sign-in');
        Route::post('settings/join-requests/{joinRequest}/approve', [WorkspaceJoinRequestController::class, 'approve'])->name('workspace.join-requests.approve');
        Route::post('settings/join-requests/{joinRequest}/deny', [WorkspaceJoinRequestController::class, 'deny'])->name('workspace.join-requests.deny');
        Route::post('settings/invitations', [WorkspaceInvitationController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('workspace.invitations.store');
        Route::delete('settings/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
            ->scopeBindings()
            ->name('workspace.invitations.destroy');

        app(Extensions::class)->loadRoutes(Extensions::WORKSPACE);

        if (CommunityLicense::shown()) {
            Route::get('settings/license', CommunityLicenseController::class)->name('workspace.settings.license');
        }
    });

if (app(Extensions::class)->marketingSite() === null) {
    Route::get('/', fn (Request $request) => Edition::home($request))->name('home');
}

Route::get('install.sh', DaemonInstallScriptController::class)->middleware('throttle:60,1')->name('daemon.install-script');

Route::get('robots.txt', RobotsTxtController::class)->middleware('throttle:60,1')->name('robots');

Route::post('locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

Route::middleware('throttle:120,1')->group(function () {
    Route::get('status/{slug}', [PublicStatusController::class, 'show'])->name('status.show');
    Route::get('status/{slug}/badge.svg', [PublicStatusController::class, 'badge'])->name('status.badge');
    Route::get('status/{slug}/embed', [PublicStatusController::class, 'embed'])->name('status.embed');
    Route::get('status/{slug}/og.png', [PublicStatusController::class, 'og'])->name('status.og');
    Route::get('status/{slug}/status.json', [PublicStatusController::class, 'json'])->name('status.json');
    Route::get('status/{slug}/feed.atom', [StatusSubscriptionController::class, 'feed'])->name('status.feed');
    Route::post('status/{slug}/subscribe', [StatusSubscriptionController::class, 'store'])->middleware('throttle:5,1')->name('status.subscribe');
    Route::get('status/{slug}/subscribe/{token}', [StatusSubscriptionController::class, 'confirm'])->name('status.subscribe.confirm');
    Route::get('status/{slug}/unsubscribe/{token}', [StatusSubscriptionController::class, 'unsubscribeShow'])->name('status.unsubscribe');
    // One-click unsubscribe POSTs without a CSRF token; the link's token stands in for it.
    Route::post('status/{slug}/unsubscribe/{token}', [StatusSubscriptionController::class, 'unsubscribe'])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('status.unsubscribe.store');
});

// One-click unsubscribe POSTs without a CSRF token; the signature stands in for it.
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('unsubscribe', [UnsubscribeController::class, 'show'])->name('unsubscribe');
    Route::post('unsubscribe', [UnsubscribeController::class, 'store'])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->name('unsubscribe.store');
});

Route::middleware(['auth', 'verified', StaffTwoFactor::class, 'can:viewAdmin'])->prefix('admin')->name('admin.')->group(function () {
    app(Extensions::class)->loadRoutes(Extensions::ADMIN);
});

Route::middleware(['auth', 'verified'])->get('github/setup', [GitHubConnectionController::class, 'setup'])->middleware('throttle:20,1')->name('github.setup');
Route::middleware(['auth', 'verified'])->get('github/app/created', [GitHubAppSetupController::class, 'created'])->middleware('throttle:10,1')->name('github.app.created');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('oauth/authorize', [OAuthAuthorizeController::class, 'show'])->name('mcp.oauth.authorize');
    Route::post('oauth/authorize', [OAuthAuthorizeController::class, 'store'])->middleware('throttle:30,1')->name('mcp.oauth.approve');
});

Route::get('workspaces/availability', WorkspaceAvailabilityController::class)
    ->middleware('throttle:60,1')
    ->name('workspaces.availability');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');
    Route::get('workspaces', WorkspaceIndexController::class)->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:10,60')->name('workspaces.store');
    Route::get('workspaces/movable-machines', [WorkspaceController::class, 'movable'])->name('workspaces.movable-machines');
    Route::get('join-requests', [WorkspaceJoinRequestController::class, 'show'])->name('join-requests.show');
});

Route::post('admin/return', ImpersonationController::class)->middleware('auth')->name('impersonation.stop');

Route::middleware('auth')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
});

require __DIR__.'/settings.php';

app(Extensions::class)->loadRoutes(Extensions::WEB);
