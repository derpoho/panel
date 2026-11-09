<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\StatusPages\FleetStatusReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's fleet status page settings (Business): one page at
 * /status/{slug} with a section for each machine it covers, and any uptime
 * monitors on no machine it picks.
 */
class WorkspaceStatusPageController extends Controller
{
    public function show(Request $request, Workspace $workspace): Response
    {
        $page = $workspace->fleetStatusPage ?? $this->draft($workspace);

        return Inertia::render('workspace/settings/StatusPage', [
            'workspace' => $workspace->toPageProps(),
            'canManage' => Gate::allows('manageMachines', $workspace),
            'planAllows' => $workspace->hasFleetStatusPage(),
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'page' => [
                'exists' => $page->exists,
                'enabled' => $page->enabled,
                'public' => $page->public,
                'slug' => $page->slug,
                'title' => $page->title,
                'pageTitle' => $page->page_title,
                'iconUrl' => $page->iconUrl(),
                'faviconUrl' => $page->faviconUrl(),
                'description' => $page->description,
                'notice' => $page->notice,
                'noticeKind' => $page->notice_kind ?? 'info',
                'showUptime' => $page->show_uptime,
                'machines' => $page->machines,
                'machineGroups' => $page->machine_groups ?? [],
                'newMachines' => $page->new_machines ?? 'machine',
                'layout' => [
                    'sections' => $page->layout['sections'] ?? [],
                    'headings' => (object) ($page->layout['headings'] ?? []),
                    'order' => $page->layout['order'] ?? [],
                ],
                'monitors' => $page->monitors ?? [],
                // People who confirmed they want email updates from it.
                'subscribers' => $page->exists ? $page->subscribers()->whereNotNull('confirmed_at')->count() : 0,
                'live' => $page->exists && $page->live(),
            ],
            'machines' => $workspace->machines()->whereNotNull('uid')->with('statusPage')->orderBy('name')->get()
                ->map(fn (Machine $machine) => [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'online' => $machine->isOnline(),
                    // Its own page picks which of its services show.
                    'hasOwnSettings' => $machine->statusPage !== null,
                ])->values(),
            'groups' => MachineGroup::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name])->values(),
            // What the page can show, to arrange into sections of its own.
            'components' => $page->exists ? FleetStatusReport::components($page) : [],
            // Monitors on no machine (a site hosted elsewhere), to pick from.
            'monitors' => Monitor::query()->where('workspace_id', $workspace->id)->whereNull('machine_id')->orderBy('name')->get()
                ->map(fn (Monitor $monitor) => [
                    'id' => $monitor->id,
                    'name' => $monitor->name,
                    'target' => $monitor->type === 'http' ? $monitor->url : "{$monitor->host}:{$monitor->port}",
                    'paused' => $monitor->paused,
                ])->values(),
        ]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $page = $workspace->fleetStatusPage ?? $this->draft($workspace);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'public' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('workspace_status_pages', 'slug')->ignore($page->id), Rule::unique('status_pages', 'slug')],
            'title' => ['nullable', 'string', 'max:80'],
            'page_title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:280'],
            'notice' => ['nullable', 'string', 'max:500'],
            'notice_kind' => ['nullable', Rule::in(StatusPage::NOTICE_KINDS)],
            'show_uptime' => ['required', 'boolean'],
            // Null: every machine, including ones connected later.
            'machines' => ['nullable', 'array', 'max:500'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            // Monitors on no machine to show; machines' monitors come with them.
            'monitors' => ['nullable', 'array', 'max:200'],
            'monitors.*' => ['integer', Rule::exists('monitors', 'id')->where('workspace_id', $workspace->id)->whereNull('machine_id')],
            // Groups whose machines it shows, as they join or leave the group.
            'machine_groups' => ['nullable', 'array', 'max:100'],
            'machine_groups.*' => ['integer', Rule::exists('machine_groups', 'id')->where('workspace_id', $workspace->id)],
            // How a machine with no page of its own shows: one row, or its running services.
            'new_machines' => ['sometimes', Rule::in(['machine', 'services'])],
            'layout' => ['sometimes', 'nullable', 'array'],
            'layout.sections' => ['sometimes', 'array', 'max:50'],
            'layout.sections.*.name' => ['nullable', 'string', 'max:60'],
            'layout.sections.*.components' => ['present', 'array', 'max:500'],
            'layout.sections.*.components.*' => ['string', 'max:320'],
            'layout.headings' => ['sometimes', 'array'],
            'layout.headings.*' => ['nullable', 'string', 'max:60'],
            'layout.order' => ['sometimes', 'array', 'max:500'],
            'layout.order.*' => ['integer'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes.',
            'slug.unique' => 'That address is taken.',
        ]);

        if ($data['enabled'] && ! $workspace->hasFleetStatusPage()) {
            return back()->withErrors(['enabled' => 'One status page for every machine comes with Business.']);
        }

        $page->fill([
            'enabled' => $data['enabled'],
            'public' => $data['public'],
            'slug' => $data['slug'],
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            'page_title' => filled($data['page_title'] ?? null) ? trim($data['page_title']) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'notice' => filled($data['notice'] ?? null) ? trim($data['notice']) : null,
            'notice_kind' => filled($data['notice'] ?? null) ? ($data['notice_kind'] ?? 'info') : null,
            'show_uptime' => $data['show_uptime'],
            'machines' => isset($data['machines']) ? array_values(array_unique(array_map('intval', $data['machines']))) : null,
            'monitors' => ! empty($data['monitors']) ? array_values(array_unique(array_map('intval', $data['monitors']))) : null,
            'machine_groups' => ! empty($data['machine_groups']) ? array_values(array_unique(array_map('intval', $data['machine_groups']))) : null,
            'new_machines' => $data['new_machines'] ?? $page->new_machines ?? 'machine',
            'layout' => array_key_exists('layout', $data) ? self::cleanLayout($data['layout'] ?? []) : $page->layout,
        ]);
        $page->workspace_id = $workspace->id;
        $page->save();

        Activity::record($workspace, 'status_page.fleet', $page->title ?: $workspace->name, meta: ['enabled' => $page->enabled]);

        return back();
    }

    /** Upload the page's icon or favicon. Saves the page (still off) if it was only a draft. */
    #[Audited]
    public function updateImage(Request $request, Workspace $workspace, string $kind): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $request->validate([$kind => WorkspaceStatusPage::brandingImageRules($kind)]);

        $page = $workspace->fleetStatusPage ?? $this->draft($workspace);
        $page->workspace_id = $workspace->id;
        $page->storeBrandingImage($kind, $request->file($kind));
        Activity::record($workspace, 'status_page.fleet', $page->title ?: $workspace->name, meta: ['changed' => $kind]);

        return back();
    }

    #[Audited]
    public function destroyImage(Workspace $workspace, string $kind): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $page = $workspace->fleetStatusPage;
        if ($page?->removeBrandingImage($kind)) {
            Activity::record($workspace, 'status_page.fleet', $page->title ?: $workspace->name, meta: ['removed' => $kind]);
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /**
     * The layout as it's kept: named sections with the components they pull
     * in, headings for machines that have one, and the machines' order.
     * Empty when there's nothing to it.
     *
     * @param  array<string, mixed>  $layout
     * @return array{sections?: list<array{name: string, components: list<string>}>, headings?: array<int|string, string>, order?: list<int>}|null
     */
    public static function cleanLayout(array $layout): ?array
    {
        $sections = array_values(array_filter(array_map(fn (array $section) => [
            'name' => trim((string) ($section['name'] ?? '')),
            'components' => array_values(array_unique(array_map('strval', $section['components'] ?? []))),
        ], $layout['sections'] ?? []), fn (array $section) => $section['components'] !== []));
        $headings = array_filter(array_map(fn ($h) => trim((string) $h), $layout['headings'] ?? []), fn (string $h) => $h !== '');
        $order = array_values(array_unique(array_map('intval', $layout['order'] ?? [])));

        return $sections === [] && $headings === [] && $order === [] ? null : array_filter([
            'sections' => $sections,
            'headings' => $headings,
            'order' => $order,
        ], fn ($v) => $v !== []);
    }

    private function draft(Workspace $workspace): WorkspaceStatusPage
    {
        $base = Str::slug($workspace->name) ?: 'status';
        $slug = $base;
        while (WorkspaceStatusPage::query()->where('slug', $slug)->exists() || StatusPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        $page = new WorkspaceStatusPage(['slug' => $slug, 'enabled' => false, 'public' => true, 'show_uptime' => true]);
        $page->setRelation('workspace', $workspace);

        return $page;
    }
}
