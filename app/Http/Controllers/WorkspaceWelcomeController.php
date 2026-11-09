<?php

namespace App\Http\Controllers;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Edition;
use App\Support\Extensions\TrialCards;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The welcome flow a new account goes through before its dashboard: what
 * it runs, how many servers and who'll use it; what its servers collect
 * and send (the default machine policy, preset from those answers); a plan
 * recommended from that, which its trial runs on; then connecting the
 * first server. Only the owner of a workspace made at signup sees it,
 * once. A self-hosted install skips the plan step. Every step can be
 * skipped, and finishing or skipping lands on the dashboard for good.
 */
class WorkspaceWelcomeController extends Controller
{
    public const array SEGMENTS = ['own', 'agency', 'hosting', 'exploring'];

    public const array SERVERS = ['1', '2-5', '6-20', '20+'];

    public function __construct(private readonly TrialCards $cards) {}

    public function show(Request $request, Workspace $workspace): Response|RedirectResponse
    {
        if (! $this->welcoming($request, $workspace)) {
            return to_route('workspace.home', ['workspace' => $workspace->slug]);
        }

        $answers = $workspace->welcome ?? [];
        $policy = MachinePolicies::defaultFor($workspace);
        $currency = Currency::forWorkspace($workspace, $request);

        return Inertia::render('welcome/Welcome', [
            'workspace' => $workspace->toPageProps(),
            'answers' => [
                'segment' => $answers['segment'] ?? null,
                'servers' => $answers['servers'] ?? null,
                'team' => $answers['team'] ?? null,
                'plan' => $answers['plan'] ?? null,
                'interval' => $answers['interval'] ?? BillingInterval::Monthly->value,
                // What they chose their servers do, once they have.
                'control' => ($answers['control'] ?? false) ? $policy->settings() : null,
            ],
            // Where step two starts for each answer to "What are you running?".
            'presets' => MachinePolicy::PRESETS,
            'recommended' => self::recommend($answers)->value,
            'plans' => array_map(fn (Plan $plan) => [
                'key' => $plan->value,
                'label' => $plan->label(),
                'monthly' => $plan->monthlyPrice($currency),
                'annual' => $plan->annualPrice($currency),
                'machines' => $plan->machineLimit(),
            ], Plan::cases()),
            'currency' => $currency->value,
            'trialDays' => Workspace::TRIAL_DAYS,
            // A card before the trial (TrialCards): the plan step asks for
            // one, charged when the trial ends, and can't be skipped.
            'cardRequired' => $this->cards->needsCard($workspace),
            'cardTrialDays' => $this->cards->trialDays($workspace),
            // Where the card is confirmed when checkout sends them back.
            'cardVerifyUrl' => $this->cards->verifyPath($workspace),
            'canConnect' => (bool) config('daemon.install_ready'),
            'apiUrl' => rtrim((string) config('app.url'), '/').'/api/v1/machines',
            // A self-hosted install has no plans, so no plan step.
            ...(Edition::selfHosted() ? ['choosePlan' => false] : []),
        ]);
    }

    /**
     * Step one: what they run, how many servers, and who'll use it.
     */
    #[NotAudited('Answers to the welcome questions, kept on the workspace')]
    public function about(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        $data = $request->validate([
            'segment' => ['required', Rule::in(self::SEGMENTS)],
            'servers' => ['required', Rule::in(self::SERVERS)],
            'team' => ['required', 'boolean'],
        ]);

        $answers = [...($workspace->welcome ?? []), ...$data, 'team' => (bool) $data['team']];
        $answers['recommended'] = self::recommend($answers)->value;
        $workspace->forceFill(['welcome' => $answers])->save();

        // Until they choose for themselves in step two, the default policy
        // is the preset for what they run: skipping it still gets a hosting
        // company no logs and updates that wait.
        if (! ($answers['control'] ?? false)) {
            MachinePolicies::defaultFor($workspace)->fill(MachinePolicy::preset($data['segment']))->save();
        }

        return back();
    }

    /**
     * Step two: what their servers collect and send. Saved as the
     * workspace's default machine policy, so every machine they connect
     * follows it from its first start.
     */
    #[Audited]
    public function control(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        $data = $request->validate([
            'discovery' => ['required', 'boolean'],
            'logs' => ['required', 'boolean'],
            'log_ip_masking' => ['required', Rule::in(MachinePolicy::MASKING)],
            'security_watch' => ['required', 'boolean'],
            'error_reports' => ['sometimes', 'boolean'],
            'status_page_new' => ['required', 'boolean'],
            'updates' => ['required', Rule::in(MachinePolicy::UPDATES)],
        ]);

        $policy = MachinePolicies::defaultFor($workspace);
        $policy->fill(array_map(fn ($value) => is_string($value) ? $value : (bool) $value, $data));
        if ($policy->isDirty()) {
            $policy->save();
            // A machine already connected (from Machines, in another tab) gets it too.
            $machines = MachinePolicies::following($policy)->pluck('id');
            MachinePolicies::push($machines);
            Activity::record($workspace, 'machine_policy.update', $policy->name, meta: ['machines' => $machines->count()]);
        } else {
            AuditContext::nothingChanged();
        }
        $workspace->forceFill(['welcome' => [...($workspace->welcome ?? []), 'control' => true]])->save();

        return back();
    }

    /**
     * Step three: the plan the trial runs on, and how they'd like to pay
     * when it ends. Nothing is charged here.
     */
    #[Audited]
    public function plan(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        abort_if(Edition::selfHosted(), 404);
        abort_if($this->cards->needsCard($workspace), 403, 'Add a card to start your trial.');
        $this->savePlan($request, $workspace);

        return back();
    }

    /**
     * Step three when the trial needs a card first (TrialCards): saves the
     * plan like plan(), then opens the card's checkout for it with the rest
     * of the trial free. The card is charged when that ends, unless they
     * cancel first.
     */
    #[Audited]
    public function card(Request $request, Workspace $workspace): JsonResponse
    {
        abort_if(Edition::selfHosted(), 404);
        abort_unless($this->welcoming($request, $workspace) && $this->cards->needsCard($workspace), 403);
        $data = $this->savePlan($request, $workspace);

        try {
            $checkoutUrl = $this->cards->checkout(
                $workspace->refresh(),
                Plan::from($data['plan']),
                BillingInterval::from($data['interval']),
                $request->user(),
                route('workspace.welcome.show', ['workspace' => $workspace->slug]),
            );
        } catch (ConnectionException) {
            return response()->json(['message' => 'Unable to reach checkout right now. Please try again.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['checkoutUrl' => $checkoutUrl]);
    }

    /**
     * The plan and billing period chosen on step three, saved; while the
     * trial runs it's the plan the trial is on.
     *
     * @return array{plan: string, interval: string}
     */
    private function savePlan(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'plan' => ['required', Rule::enum(Plan::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ]);

        $before = $workspace->effectivePlan();
        $workspace->forceFill([
            'welcome' => [...($workspace->welcome ?? []), ...$data],
            // Only a trial that's running changes plan; a paid plan is
            // chosen in Billing.
            'trial_plan' => $workspace->onTrial() ? $data['plan'] : $workspace->trial_plan,
        ])->save();

        if ($workspace->onTrial() && $before !== $workspace->effectivePlan()) {
            Activity::record($workspace, 'workspace.trial_plan', $workspace->effectivePlan()?->label(), meta: ['from' => $before?->label()]);
        }

        return $data;
    }

    /**
     * Done, or skipped: from now on the workspace opens on its dashboard.
     */
    #[NotAudited('Marks the welcome flow as seen')]
    public function finish(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($workspace->owner_id === $request->user()->id, 403);
        $data = $request->validate(['skipped_at' => ['nullable', 'string', 'in:about,control,plan,connect']]);

        // A trial that needs a card first: no dashboard without one.
        if ($this->cards->needsCard($workspace)) {
            return to_route('workspace.welcome.show', ['workspace' => $workspace->slug]);
        }

        if ($workspace->onboarded_at === null) {
            $workspace->forceFill([
                'onboarded_at' => now(),
                'welcome' => [...($workspace->welcome ?? []), 'skipped_at' => $data['skipped_at'] ?? null],
            ])->save();
        }

        return to_route('workspace.home', ['workspace' => $workspace->slug]);
    }

    /** Whether this person should see the welcome flow for this workspace. */
    private function welcoming(Request $request, Workspace $workspace): bool
    {
        return $workspace->onboarded_at === null && $workspace->owner_id === $request->user()->id;
    }

    /**
     * The plan that fits what they told us: one server is Essential, a
     * handful is Pro, and more than that (or a hosting company or agency
     * with a team) is Business. With no answers yet, the usual Pro trial.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function recommend(array $answers): Plan
    {
        $servers = $answers['servers'] ?? null;
        $team = (bool) ($answers['team'] ?? false);
        $business = in_array($answers['segment'] ?? null, ['hosting', 'agency'], true) && $team;

        return match (true) {
            $servers === null => Workspace::TRIAL_PLAN,
            in_array($servers, ['6-20', '20+'], true), $business => Plan::Business,
            $servers === '2-5', $team => Plan::Pro,
            default => Plan::Essential,
        };
    }
}
