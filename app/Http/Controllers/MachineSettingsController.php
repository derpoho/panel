<?php

namespace App\Http\Controllers;

use App\Enums\MachineEnvironment;
use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\MachinePolicy;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\DaemonGatewayClient;
use App\Support\DaemonJobQueue;
use App\Support\DaemonUpdates;
use App\Support\Logs\LogSkipRules;
use App\Support\Machines\MachineDataEraser;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use App\Support\StatusPages\StatusReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class MachineSettingsController extends Controller
{
    /**
     * The machine's settings: its name and environment, its status page,
     * and removing it.
     */
    public function show(Request $request, Workspace $workspace, Machine $machine): Response
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $page = $machine->statusPage ?? $this->draft($machine);
        $report = new StatusReport($page);
        $config = $page->services ?? [];
        $inventory = array_filter($machine->inventory['services'] ?? [], fn ($s) => is_array($s) && is_string($s['key'] ?? null));
        $suggested = StatusReport::friendlyNames(array_column(array_map(fn (array $s) => [$s['key'], (string) $s['name']], $inventory), 1, 0));

        return Inertia::render('workspace/MachineSettings', [
            'workspace' => $workspace->toPageProps(),
            'machine' => (new MachineResource($machine))->resolve(),
            'canManage' => Gate::allows('manageMachines', $workspace),
            // The policy it follows and why, what that has it send, and
            // whether its daemon has it yet.
            'policy' => $this->policyProps($workspace, $machine),
            // Services and lines whose logs never leave the machine.
            'logSkip' => [
                'available' => DaemonJobQueue::canSkipLogs($machine),
                'since' => 'v'.config('daemon.log_skip_since'),
                'rules' => $machine->log_skip ?? LogSkipRules::none(),
            ],
            'statusPage' => [
                'exists' => $page->exists,
                'enabled' => $page->enabled,
                'public' => $page->public,
                'slug' => $page->slug,
                'title' => $page->title,
                'pageTitle' => $page->page_title,
                'iconUrl' => $page->iconUrl(),
                'faviconUrl' => $page->faviconUrl(),
                'description' => $page->description,
                'includeNew' => $page->include_new,
                'showUptime' => $page->show_uptime,
                'notice' => $page->notice,
                'noticeKind' => $page->notice_kind ?? 'info',
                // On the main domain, not this workspace's subdomain.
                'url' => self::onMainDomain(route('status.show', $page->slug, false)),
                'badgeUrl' => self::onMainDomain(route('status.badge', $page->slug, false)),
                'jsonUrl' => self::onMainDomain(route('status.json', $page->slug, false)),
                // People who confirmed they want email updates from it.
                'subscribers' => $page->exists ? $page->subscribers()->whereNotNull('confirmed_at')->count() : 0,
                // Every service the machine has, and how the page treats it.
                'services' => array_values(array_map(fn (array $service) => [
                    'key' => $service['key'],
                    'name' => $service['name'],
                    'kind' => $service['kind'] ?? 'other',
                    'image' => $service['details']['image'] ?? null,
                    'status' => $service['status'] ?? 'unknown',
                    'shown' => $config[$service['key']]['shown'] ?? $page->include_new,
                    'quiet' => (bool) ($config[$service['key']]['quiet'] ?? false),
                    'label' => $config[$service['key']]['label'] ?? null,
                    'suggested' => $suggested[$service['key']] ?? StatusReport::friendlyName((string) $service['name']),
                ], $inventory)),
                // The machine's monitors, keyed like services in the settings.
                'monitors' => $report->allMonitors()->map(fn (Monitor $monitor) => [
                    'key' => 'monitor:'.$monitor->id,
                    'id' => $monitor->id,
                    'name' => $monitor->name,
                    'target' => $monitor->target(),
                    'status' => $monitor->status,
                    'shown' => $config['monitor:'.$monitor->id]['shown'] ?? $page->include_new,
                    'quiet' => (bool) ($config['monitor:'.$monitor->id]['quiet'] ?? false),
                    'label' => $config['monitor:'.$monitor->id]['label'] ?? null,
                ])->values(),
                'preview' => $report->toArray(),
            ],
            // What it has sent and how much, asked for once the page is open:
            // counting can take a moment on a busy machine.
            'collectedData' => Inertia::optional(fn () => app(MachineDataEraser::class)->summary($machine)),
            'planAllows' => $workspace->hasStatusPages(),
            'environments' => array_map(fn (MachineEnvironment $e) => $e->value, MachineEnvironment::cases()),
        ]);
    }

    /**
     * The policy this machine follows: its own pick, or null to follow its
     * group's (else the workspace's default). Sent to the daemon straight
     * away, or when it's next online.
     */
    #[Audited]
    public function updatePolicy(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'policy' => ['nullable', 'integer', Rule::exists('machine_policies', 'id')->where('workspace_id', $workspace->id)],
        ]);

        $before = MachinePolicies::for($machine);
        $machine->forceFill(['machine_policy_id' => $data['policy'] ?? null])->save();
        $machine->unsetRelation('policy');
        $after = MachinePolicies::for($machine);
        if ($machine->wasChanged('machine_policy_id')) {
            Activity::record($workspace, 'machine.policy', $machine->name, $machine, meta: ['from' => $before->name, 'to' => $after->name]);
        } else {
            AuditContext::nothingChanged();
        }
        if ($before->id !== $after->id) {
            MachinePolicies::sync($machine);
        }

        return back();
    }

    /**
     * Install the newest daemon release on this machine now: how a policy
     * that waits for approval gets its updates.
     */
    #[Audited]
    public function installUpdate(Workspace $workspace, Machine $machine, DaemonUpdates $updates): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        try {
            $release = $updates->install($machine);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['update' => $e->getMessage()]);
        }
        Activity::record($workspace, 'machine.daemon_update', $machine->name, $machine, meta: ['from' => $machine->daemon_version, 'to' => $release->version]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is updating to :version. It reconnects by itself in a minute or so.', ['name' => $machine->name, 'version' => $release->version])]);

        return back();
    }

    /**
     * Services and line patterns whose logs never leave the machine. Sent
     * to the daemon, which keeps them in its daemon.toml and checks every
     * line against them before it's shipped, shown or tailed.
     */
    #[Audited]
    public function updateLogSkip(Request $request, Workspace $workspace, Machine $machine, DaemonGatewayClient $gateway): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $request->validate([
            'services' => ['present', 'array'],
            'services.*' => ['nullable', 'string'],
            'patterns' => ['present', 'array'],
            'patterns.*.service' => ['nullable', 'string'],
            'patterns.*.pattern' => ['nullable', 'string'],
        ]);
        $rules = LogSkipRules::fromInput($request->only('services', 'patterns'));

        if (! DaemonJobQueue::canSkipLogs($machine)) {
            throw ValidationException::withMessages(['services' => 'This machine’s daemon is too old to skip logs. It updates itself, or run “sudo serverosd update” on the server.']);
        }

        try {
            $gateway->control($machine, ['type' => 'reconfigure', 'log_skip' => $rules]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['services' => $e->getMessage().' Try again once it’s online.']);
        }

        $before = $machine->log_skip ?? LogSkipRules::none();
        if ($before === $rules) {
            AuditContext::nothingChanged();

            return back();
        }
        $machine->forceFill(['log_skip' => $rules])->save();
        Activity::record($workspace, 'machine.log_skip', $machine->name, $machine, meta: [
            'services' => count($rules['services']),
            'patterns' => count($rules['patterns']),
        ]);

        // Newly skipped services may have sent lines already: offer to
        // delete those. Past lines matching a new pattern can't be found
        // safely in SQL, so the page only says they stay.
        $added = LogSkipRules::added($before, $rules);
        $services = MachineDataEraser::skippedServices($machine, $added['services']);
        $patterns = $added['patterns'] !== [] && MachineDataEraser::storedLogLines($machine) > 0;
        if ($services !== [] || $patterns) {
            Inertia::flash('logSkipOffer', [
                'services' => $services,
                'lines' => array_sum(array_column($services, 'lines')),
                'patterns' => $patterns,
            ]);
        }

        return back();
    }

    /**
     * Rename the machine or change its environment.
     */
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', Rule::enum(MachineEnvironment::class)],
            // Its details. Empty provider or region: detection again.
            'provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'labels' => ['sometimes', 'nullable', 'array', 'max:'.MachineMetadata::MAX_LABELS],
            'labels.*.key' => ['required', 'string'],
            'labels.*.value' => ['nullable', 'string'],
        ]);

        $machine->forceFill([
            'name' => trim($data['name']),
            'environment' => MachineEnvironment::from($data['environment']),
        ]);
        if ($machine->isDirty('name')) {
            Activity::record($workspace, 'machine.rename', $machine->name, $machine, meta: ['from' => $machine->getOriginal('name')]);
        }
        if ($machine->isDirty('environment')) {
            Activity::record($workspace, 'machine.environment', $machine->name, $machine, meta: ['to' => $machine->environment->value]);
        }

        $metadata = array_intersect_key($data, array_flip(['provider', 'region', 'tier']));
        if (array_key_exists('labels', $data)) {
            /** @var list<array{key: string, value: string|null}> $labels validated above */
            $labels = $data['labels'] ?? [];
            $metadata['labels'] = collect($labels)->mapWithKeys(fn (array $label) => [trim($label['key']) => $label['value'] ?? ''])->all();
            $metadata['replaceLabels'] = true;
        }
        // Its details can match another policy's rules.
        $changed = MachinePolicies::whileChanging($workspace, [$machine->id], function () use ($machine, $metadata) {
            $changed = MachineMetadata::apply($machine, $metadata);
            $machine->save();

            return $changed;
        });
        if ($changed !== []) {
            Activity::record($workspace, 'machine.metadata', $machine->name, $machine, meta: ['fields' => $changed]);
        }
        if (! $machine->wasChanged(['name', 'environment']) && $changed === []) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * Turn the status page on or off and choose what it shows.
     */
    #[Audited]
    public function updateStatusPage(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $page = $machine->statusPage ?? $this->draft($machine);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'public' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('status_pages', 'slug')->ignore($page->id), Rule::unique('workspace_status_pages', 'slug')],
            'title' => ['nullable', 'string', 'max:80'],
            'page_title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:280'],
            'include_new' => ['required', 'boolean'],
            'show_uptime' => ['required', 'boolean'],
            'notice' => ['nullable', 'string', 'max:500'],
            'notice_kind' => ['nullable', Rule::in(StatusPage::NOTICE_KINDS)],
            // A list, not keyed: service keys hold dots (nginx.service).
            'services' => ['present', 'array', 'list', 'max:500'],
            'services.*.key' => ['required', 'string', 'max:300'],
            'services.*.shown' => ['required', 'boolean'],
            'services.*.label' => ['nullable', 'string', 'max:60'],
            // Only while there's a problem: off the page while it's fine.
            'services.*.quiet' => ['sometimes', 'boolean'],
            // A monitor's own section on the page (another location, say).
            'services.*.section' => ['sometimes', 'nullable', 'string', 'max:60'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes.',
            'slug.unique' => 'That address is taken.',
        ]);

        if ($data['enabled'] && ! $workspace->hasStatusPages()) {
            return back()->withErrors(['enabled' => 'Status pages come with Pro. Choose Pro or Business to turn one on.']);
        }

        $services = [];
        foreach ($data['services'] as $service) {
            $label = trim((string) ($service['label'] ?? ''));
            $services[$service['key']] = ['shown' => (bool) $service['shown'], 'label' => $label === '' ? null : $label];
            if ($service['shown'] && ($service['quiet'] ?? false)) {
                $services[$service['key']]['quiet'] = true;
            }
            // A monitor's own section: as sent, or as it was.
            $section = array_key_exists('section', $service) ? $service['section'] : ($page->services[$service['key']]['section'] ?? null);
            $section = trim((string) $section);
            if ($section !== '' && str_starts_with($service['key'], 'monitor:')) {
                $services[$service['key']]['section'] = $section;
            }
        }

        $page->fill([
            'enabled' => $data['enabled'],
            'public' => $data['public'],
            'slug' => $data['slug'],
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            'page_title' => filled($data['page_title'] ?? null) ? trim($data['page_title']) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'include_new' => $data['include_new'],
            'show_uptime' => $data['show_uptime'],
            'notice' => filled($data['notice'] ?? null) ? trim($data['notice']) : null,
            'notice_kind' => filled($data['notice'] ?? null) ? ($data['notice_kind'] ?? 'info') : null,
            'services' => $services,
        ]);
        $page->machine_id = $machine->id;
        $wasLive = (bool) $page->getOriginal('enabled');
        $page->save();
        Activity::record($workspace, match (true) {
            $page->enabled && ! $wasLive => 'status_page.publish',
            ! $page->enabled && $wasLive => 'status_page.unpublish',
            default => 'status_page.update',
        }, $page->title ?: $machine->name, $machine);

        return back();
    }

    /** Upload the status page's icon or favicon. Saves the page (still off) if it was only a draft. */
    #[Audited]
    public function updateStatusImage(Request $request, Workspace $workspace, Machine $machine, string $kind): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $request->validate([$kind => StatusPage::brandingImageRules($kind)]);

        $page = $machine->statusPage ?? $this->draft($machine);
        $page->storeBrandingImage($kind, $request->file($kind));
        Activity::record($workspace, 'status_page.update', $page->title ?: $machine->name, $machine);

        return back();
    }

    #[Audited]
    public function destroyStatusImage(Workspace $workspace, Machine $machine, string $kind): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if ($machine->statusPage?->removeBrandingImage($kind)) {
            Activity::record($workspace, 'status_page.update', $machine->statusPage->title ?: $machine->name, $machine);
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function policyProps(Workspace $workspace, Machine $machine): array
    {
        ['policy' => $policy, 'from' => $from, 'group' => $group] = MachinePolicies::resolve($machine);
        $release = DaemonUpdates::available($machine);

        return [
            'current' => $policy->toPageProps(),
            'from' => $from,
            'group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            // The rule that picked it, when one did: tier is performance, say.
            'rule' => $from === 'rule' ? collect($policy->applies_to ?? [])->first(fn (array $rule) => MachineMetadata::matches($machine, $rule['field'], $rule['value'])) : null,
            // Its own pick; null follows its group's, else the default.
            'choice' => $machine->machine_policy_id,
            'options' => $workspace->machinePolicies()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (MachinePolicy $p) => ['id' => $p->id, 'name' => $p->name, 'isDefault' => $p->is_default])->values(),
            'pending' => $machine->policy_pending,
            'supported' => DaemonJobQueue::canFollowPolicy($machine),
            'since' => 'v'.config('daemon.policy_since'),
            'daemon' => [
                'version' => $machine->daemon_version,
                'available' => $release?->version,
                'notesUrl' => $release?->notes_url,
                // Every change between what it runs and the release, in the source.
                'compareUrl' => $release !== null && preg_match('/^v?\d+\.\d+\.\d+/', (string) $machine->daemon_version) === 1
                    ? 'https://github.com/serveroshq/daemon/compare/v'.ltrim((string) $machine->daemon_version, 'v').'...'.$release->version
                    : null,
                'pinned' => $policy->updates === 'pinned' ? $policy->pinned_version : null,
                'online' => $machine->isOnline(),
            ],
        ];
    }

    private static function onMainDomain(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * An unsaved page with a free address, so settings have something to show.
     */
    private function draft(Machine $machine): StatusPage
    {
        return StatusPage::draftFor($machine);
    }

    /** Upload an icon for the machine, replacing any it had. SVGs aren't taken: they can carry scripts. */
    #[NotAudited('Cosmetic')]
    public function updateIcon(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $request->validate(['icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000']]);

        $previous = $machine->icon_path;
        $machine->forceFill(['icon_path' => $request->file('icon')->store('machine-icons', 'public')])->save();
        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return back();
    }

    #[NotAudited('Cosmetic')]
    public function destroyIcon(Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if ($machine->icon_path !== null) {
            Storage::disk('public')->delete($machine->icon_path);
            $machine->forceFill(['icon_path' => null])->save();
        }

        return back();
    }
}
