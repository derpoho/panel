<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Support\Domains\CustomDomains;
use App\Support\Edition;
use App\Support\Entitlements\Entitlements;
use App\Support\Extensions\Billing;
use App\Support\Extensions\Extensions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property Plan|null $plan
 * @property BillingInterval|null $billing_interval
 * @property CarbonImmutable|null $trial_ends_at
 * @property Plan|null $trial_plan the plan its trial runs on, when it isn't TRIAL_PLAN
 * @property CarbonImmutable|null $trial_last_ends_at when the trial ended or ends, kept once a plan is chosen
 * @property CarbonImmutable|null $trial_reminded_3d_at when the "3 days left" email went
 * @property CarbonImmutable|null $trial_reminded_1d_at when the "last day" email went
 * @property CarbonImmutable|null $trial_ended_emailed_at when the "trial has ended" email went
 * @property CarbonImmutable|null $trial_emails_off_at when the owner turned the trial emails off
 * @property string|null $signup_variant the signup test's half it's in, null for everyone else
 * @property CarbonImmutable|null $card_added_at when its owner added a card to start the trial
 * @property string|null $plan_source manual when ServerOS staff set it, or what the billing package says (Billing)
 * @property CarbonImmutable|null $manual_plan_until when a manual plan ends; null for no end
 * @property int|null $machine_limit_override a machine allowance in place of the plan's; -1 for unlimited
 * @property int|null $mcp_call_limit_override AI tool calls a month in place of the plan's; -1 for unlimited
 * @property string|null $plan_note why the plan was set by hand
 * @property CarbonImmutable|null $cloud_credit_used_at when its first Cloud order's credit was used
 * @property CarbonInterface|null $plan_grace_until when a plan whose renewal didn't go through ends, unless it's paid
 * @property bool $alert_email
 * @property bool $require_two_factor members need two-factor sign-in or a passkey
 * @property string $domain_join off, auto or request: who signing in with Google on a verified domain may join
 * @property string $domain_join_role the built-in role people joining that way get
 * @property int|null $domain_join_custom_role_id a custom role on top of it (Business)
 * @property bool $require_google_sign_in members need to sign in with Google on one of the workspace's domains
 * @property bool $require_sso members need to sign in through the workspace's SSO connection (a package's column)
 * @property string $scim_default_role the built-in role people provisioned over SCIM get, unless their groups decide it (a package's column)
 * @property int|null $scim_default_custom_role_id a custom role on top of it (Business; a package's column)
 * @property string|null $alert_webhook_url
 * @property list<string>|null $alert_muted
 * @property string|null $company_legal_name the company's registered name, for agreements staff send
 * @property string|null $company_number
 * @property string|null $company_tax_id VAT or other tax number
 * @property string|null $company_address
 * @property string|null $company_country
 * @property string|null $company_contact_name who agreements are addressed to and signed by
 * @property string|null $company_contact_title
 * @property string|null $company_contact_email
 * @property array{segment?: string, servers?: string, team?: bool, recommended?: string, plan?: string, interval?: string, control?: bool, skipped_at?: string|null}|null $welcome what it told the welcome flow (WorkspaceWelcomeController)
 * @property array<string, string|null>|null $dismissed_issues issue id => the "since" it was marked read at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $pivot the viewer's membership, when loaded through User::workspaces()
 */
#[Fillable(['name', 'slug', 'plan', 'billing_interval', 'trial_ends_at'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /** New workspaces try Pro for this many days before choosing a plan. */
    public const int TRIAL_DAYS = 14;

    /** The plan a workspace gets while on trial, unless it chose another when it signed up. */
    public const Plan TRIAL_PLAN = Plan::Pro;

    /** Days a plan is kept after a renewal payment fails, to sort out the card. */
    public const int GRACE_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A package's own columns here (Extensions::addModelCasts).
            ...app(Extensions::class)->modelCasts(self::class),
            'plan' => Plan::class,
            'billing_interval' => BillingInterval::class,
            'trial_ends_at' => 'datetime',
            'require_two_factor' => 'boolean',
            'require_google_sign_in' => 'boolean',
            'require_sso' => 'boolean',
            'manual_plan_until' => 'datetime',
            'plan_grace_until' => 'datetime',
            'cloud_credit_used_at' => 'immutable_datetime',
            'machine_limit_override' => 'integer',
            'mcp_call_limit_override' => 'integer',
            'alert_email' => 'boolean',
            'alert_webhook_url' => 'encrypted',
            'alert_muted' => 'array',
            'dismissed_issues' => 'array',
            'welcome' => 'array',
            'onboarded_at' => 'datetime',
            'trial_plan' => Plan::class,
            'trial_reminded_3d_at' => 'datetime',
            'trial_reminded_1d_at' => 'datetime',
            'trial_ended_emailed_at' => 'datetime',
            'trial_emails_off_at' => 'datetime',
            'trial_last_ends_at' => 'datetime',
            'card_added_at' => 'datetime',
        ];
    }

    /** The columns trials:remind marks as it sends each trial email. */
    public const array TRIAL_REMINDER_FLAGS = ['trial_reminded_3d_at', 'trial_reminded_1d_at', 'trial_ended_emailed_at'];

    protected static function booted(): void
    {
        static::saving(function (Workspace $workspace): void {
            if (! $workspace->isDirty('trial_ends_at') || $workspace->trial_ends_at === null) {
                return;
            }

            // Kept after a plan is chosen (which clears trial_ends_at), for the trial stats.
            $workspace->trial_last_ends_at = $workspace->trial_ends_at;

            // A new end date (staff extending it, a survey reward): its reminders go out again.
            if ($workspace->exists && $workspace->trial_ends_at->isFuture()) {
                $workspace->forceFill(array_fill_keys(self::TRIAL_REMINDER_FLAGS, null));
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Everyone with access to the workspace, including the owner.
     *
     * @return BelongsToMany<User, $this, Membership>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role', 'custom_role_id')
            ->withTimestamps();
    }

    /** @return HasMany<WorkspaceCustomRole, $this> */
    public function customRoles(): HasMany
    {
        return $this->hasMany(WorkspaceCustomRole::class)->orderBy('name');
    }

    /**
     * Whether the workspace can define its own roles on its current plan.
     */
    public function hasCustomRoles(): bool
    {
        return $this->hasFeature(Feature::CustomRoles);
    }

    /**
     * The custom role the user holds, if any and if the plan still allows it.
     */
    public function customRoleOf(User $user): ?WorkspaceCustomRole
    {
        if (! $this->hasCustomRoles()) {
            return null;
        }

        $id = $this->members()->whereKey($user->id)->value('user_workspace.custom_role_id');

        return $id === null ? null : $this->customRoles()->whereKey($id)->first();
    }

    /**
     * Whether the user meets the workspace's two-factor requirement: a
     * confirmed authenticator app or at least one passkey. A session a
     * package vouches for meets it too (SignInHooks::meetsTwoFactor), such
     * as one signed in through this workspace's own SSO connection, since
     * the company's identity provider is where its people prove who they
     * are, second factor included. Only for a session: API keys and MCP
     * pass no request.
     */
    public function satisfiesTwoFactor(User $user, ?Request $request = null): bool
    {
        if (! $this->require_two_factor || $user->two_factor_confirmed_at !== null || $user->passkeys()->exists()) {
            return true;
        }

        foreach ($request === null ? [] : app(Extensions::class)->signInHooks() as $hooks) {
            if ($hooks->meetsTwoFactor($this, $user, $request)) {
                return true;
            }
        }

        return false;
    }

    /** Single sign-on comes with Business. */
    public function hasSso(): bool
    {
        return $this->hasFeature(Feature::Sso);
    }

    /**
     * The mail domains the workspace has added to verify, for company Google
     * sign-in, single sign-on and SCIM.
     *
     * @return HasMany<WorkspaceEmailDomain, $this>
     */
    public function emailDomains(): HasMany
    {
        return $this->hasMany(WorkspaceEmailDomain::class)->orderBy('domain');
    }

    /**
     * People asking to join through their company's domain.
     *
     * @return HasMany<WorkspaceJoinRequest, $this>
     */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(WorkspaceJoinRequest::class);
    }

    /**
     * Verified domains and Google sign-in rules, on Pro and Business (the
     * plans with staff roles, which people joining this way usually get).
     */
    public function hasGoogleTeamAccess(): bool
    {
        return $this->hasStaffRoles();
    }

    /**
     * Whether the person may use the workspace as signed in now: when it
     * requires Google sign-in, that this session came through Google with
     * an account on one of its domains. The owner always may, so nobody
     * locks themselves out.
     */
    public function satisfiesGoogleSignIn(User $user, ?string $via, ?string $hd): bool
    {
        if (! $this->require_google_sign_in || $this->owner_id === $user->id) {
            return true;
        }

        return $via === 'google' && $hd !== null
            && $this->emailDomains()->where('domain', strtolower($hd))->exists();
    }

    /**
     * People added, updated and removed by the company's identity provider
     * over SCIM, on Business.
     */
    public function hasScim(): bool
    {
        return $this->hasFeature(Feature::Scim);
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /**
     * Incidents written for its status pages.
     *
     * @return HasMany<StatusIncident, $this>
     */
    public function statusIncidents(): HasMany
    {
        return $this->hasMany(StatusIncident::class);
    }

    /**
     * @return HasMany<Machine, $this>
     */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<MachineGroup, $this>
     */
    public function machineGroups(): HasMany
    {
        return $this->hasMany(MachineGroup::class);
    }

    /**
     * @return HasMany<MachinePolicy, $this>
     */
    public function machinePolicies(): HasMany
    {
        return $this->hasMany(MachinePolicy::class);
    }

    /**
     * The GitHub accounts and organisations whose private repositories it
     * can deploy.
     *
     * @return HasMany<GitHubInstallation, $this>
     */
    public function githubInstallations(): HasMany
    {
        return $this->hasMany(GitHubInstallation::class);
    }

    /**
     * @return HasMany<BackupDestination, $this>
     */
    public function backupDestinations(): HasMany
    {
        return $this->hasMany(BackupDestination::class);
    }

    /**
     * Pending invitations to join the workspace.
     *
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * The user's role in the workspace, or null if they aren't a member.
     */
    public function roleOf(User $user): ?WorkspaceRole
    {
        $role = $this->members()->whereKey($user->id)->value('user_workspace.role');

        return $role === null ? null : WorkspaceRole::tryFrom($role);
    }

    /**
     * Whether the user's role grants the permission.
     */
    public function allows(User $user, WorkspacePermission $permission): bool
    {
        return self::grants($this->accessOf($user), $permission);
    }

    /**
     * The user's role and custom role here, looked up once, for checking
     * many permissions in a row (grants) without a query each.
     *
     * @return array{role: ?WorkspaceRole, custom: ?WorkspaceCustomRole}
     */
    public function accessOf(User $user): array
    {
        $role = $this->roleOf($user);

        // Owners always keep everything, so their custom role never counts.
        return ['role' => $role, 'custom' => $role === WorkspaceRole::Owner ? null : $this->customRoleOf($user)];
    }

    /**
     * Whether access from accessOf grants the permission: a custom role
     * replaces the base role's permissions while the plan allows custom roles.
     *
     * @param  array{role: ?WorkspaceRole, custom: ?WorkspaceCustomRole}  $access
     */
    public static function grants(array $access, WorkspacePermission $permission): bool
    {
        if ($access['custom'] !== null) {
            return $access['custom']->grants($permission);
        }

        return $access['role']?->can($permission) ?? false;
    }

    /**
     * Whether people can be given staff roles (Member, Viewer) on the
     * workspace's current plan.
     */
    public function hasStaffRoles(): bool
    {
        return $this->hasFeature(Feature::StaffRoles);
    }

    /**
     * Public URL of the workspace's uploaded logo, if any.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Whether the workspace is trying ServerOS without having chosen a plan.
     */
    public function onTrial(): bool
    {
        return $this->plan === null && $this->trial_ends_at?->isFuture() === true;
    }

    /**
     * The plan whose limits apply right now: the chosen plan, the trial plan,
     * or null once a trial has ended without a plan being chosen.
     */
    public function effectivePlan(): ?Plan
    {
        return $this->plan ?? ($this->onTrial() || $this->awaitingFirstCharge() ? ($this->trial_plan ?? self::TRIAL_PLAN) : null);
    }

    /**
     * Whether the workspace is running: on serveros.com it has a plan or a
     * trial; a self-hosted workspace always is. Ask this, not effectivePlan(),
     * to decide whether something may run.
     */
    public function isActive(): bool
    {
        return app(Entitlements::class)->active($this);
    }

    /** Whether the workspace may use a feature, wherever it runs. */
    public function hasFeature(Feature $feature): bool
    {
        return app(Entitlements::class)->has($this, $feature);
    }

    /** A limit's value for the workspace, or null for none. */
    public function limitOf(Limit $limit): ?int
    {
        return app(Entitlements::class)->limit($this, $limit);
    }

    /**
     * A card trial (TrialCards) whose end has passed, its card's first
     * charge not confirmed yet: the subscription charges when its own trial
     * ends, so the plan carries on until the renewal arrives. A cancelled
     * or failed subscription isn't waited for.
     */
    public function awaitingFirstCharge(): bool
    {
        return $this->plan === null && $this->card_added_at !== null && app(Billing::class)->isBilled($this);
    }

    /**
     * How many machines the workspace may have: null for unlimited, 0 when
     * there is no plan.
     */
    public function machineLimit(): ?int
    {
        return $this->limitOf(Limit::Machines);
    }

    /**
     * AI tool calls the workspace gets a month before any packs: null for
     * unlimited, 0 when there is no plan. A number set from /admin
     * replaces the plan's.
     */
    public function mcpCallLimit(): ?int
    {
        return $this->limitOf(Limit::McpCalls);
    }

    /**
     * One status page for the whole fleet comes with Business.
     */
    public function hasFleetStatusPage(): bool
    {
        return $this->hasFeature(Feature::FleetStatusPage);
    }

    /**
     * The workspace's own domains come with Business.
     */
    public function hasCustomDomains(): bool
    {
        return $this->hasFeature(Feature::CustomDomains);
    }

    public function hasOwnLogStorage(): bool
    {
        return $this->hasFeature(Feature::OwnLogStorage);
    }

    /** @return HasMany<WorkspaceDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(WorkspaceDomain::class);
    }

    /** @return HasOne<WorkspaceStatusPage, $this> */
    public function fleetStatusPage(): HasOne
    {
        return $this->hasOne(WorkspaceStatusPage::class);
    }

    /**
     * Status pages come with Pro and Business (and the Pro trial).
     */
    public function hasStatusPages(): bool
    {
        return $this->hasFeature(Feature::StatusPages);
    }

    /**
     * Workflows come with Pro and Business (and the Pro trial).
     */
    public function hasAutomations(): bool
    {
        return $this->hasFeature(Feature::Automations);
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * How many days of service logs are kept: the plan's, or a day when
     * there is no plan.
     */
    public function logRetentionDays(): int
    {
        return (int) $this->limitOf(Limit::LogRetentionDays);
    }

    /** Log lines kept a day: the plan's, else enough to try logs out. */
    public function logLinesPerDay(): int
    {
        return (int) $this->limitOf(Limit::LogLinesPerDay);
    }

    /**
     * How many days of activity are kept: the paid plan's, else the 90
     * everyone gets (a trial too, so ending one never shortens it).
     */
    public function activityRetentionDays(): int
    {
        return (int) $this->limitOf(Limit::ActivityRetentionDays);
    }

    /**
     * How many uptime monitors the plan allows (none without a plan).
     */
    public function monitorLimit(): int
    {
        return (int) $this->limitOf(Limit::Monitors);
    }

    /**
     * The shortest check interval the plan allows, in seconds.
     */
    public function minMonitorInterval(): int
    {
        return (int) $this->limitOf(Limit::MinMonitorInterval);
    }

    /**
     * Whether another machine fits within the workspace's plan.
     */
    public function canAddMachine(): bool
    {
        $limit = $this->machineLimit();

        return $limit === null || $this->machines()->count() < $limit;
    }

    /**
     * Switch to a plan, ending any trial.
     */
    public function choosePlan(Plan $plan, BillingInterval $interval): void
    {
        $this->forceFill([
            'plan' => $plan,
            'billing_interval' => $interval,
            'trial_ends_at' => null,
        ])->save();
    }

    /**
     * Whether ServerOS staff set the plan by hand rather than it being paid.
     */
    public function hasManualPlan(): bool
    {
        return $this->plan !== null && $this->plan_source === 'manual';
    }

    /**
     * Plan, trial and usage as pages receive them.
     *
     * @return array{plan: string|null, planLabel: string|null, interval: string|null, subscriptionStatus: string|null, graceUntil: string|null, hasBillingPortal: bool, onTrial: bool, trialEndsAt: string|null, trialDaysLeft: int|null, machineCount: int, machineLimit: int|null, canAddMachine: bool, contract: array<string, mixed>|null, selfHosted?: true}
     */
    public function billingSummary(): array
    {
        $machineCount = $this->machines()->count();
        $limit = $this->machineLimit();
        $onTrial = $this->onTrial();
        $billing = app(Billing::class)->summary($this);

        return [
            'plan' => $this->effectivePlan()?->value,
            'planLabel' => $this->effectivePlan()?->label(),
            'interval' => $this->billing_interval?->value,
            'subscriptionStatus' => $billing['subscriptionStatus'],
            // A renewal didn't go through: the plan ends then unless it's paid.
            'graceUntil' => $this->plan !== null ? $this->plan_grace_until?->toIso8601String() : null,
            'hasBillingPortal' => $billing['hasBillingPortal'],
            'onTrial' => $onTrial,
            'trialEndsAt' => $onTrial ? $this->trial_ends_at?->toIso8601String() : null,
            'trialDaysLeft' => $onTrial ? max(1, (int) ceil(now()->diffInDays($this->trial_ends_at))) : null,
            'machineCount' => $machineCount,
            'machineLimit' => $limit,
            'canAddMachine' => $limit === null || $machineCount < $limit,
            'contract' => $billing['contract'],
            // A self-hosted install: active with no plan, so nothing asks
            // for one.
            ...(Edition::selfHosted() ? ['selfHosted' => true] : []),
        ];
    }

    /**
     * The workspace's hostname, e.g. acme.serveros.com.
     */
    /**
     * Where the dashboard lives: the workspace's own domain once it's
     * active (Business), else its subdomain of ours.
     */
    public function host(): string
    {
        return CustomDomains::dashboardHostFor($this->slug) ?? $this->subdomain();
    }

    /** Its subdomain of ours, which always works (MCP lives here). */
    public function subdomain(): string
    {
        return $this->slug.'.'.config('app.domain');
    }

    /**
     * The workspace as Inertia pages receive it.
     *
     * @return array{id: int, name: string, slug: string, host: string, logoUrl: string|null, billing: array<string, mixed>}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'host' => $this->host(),
            'logoUrl' => $this->logoUrl(),
            'billing' => $this->billingSummary(),
        ];
    }

    /**
     * The absolute URL of the workspace's dashboard on its subdomain.
     */
    public function url(): string
    {
        return route('workspace.home', ['workspace' => $this->slug]);
    }
}
