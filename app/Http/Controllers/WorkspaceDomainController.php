<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Docs\DocsUrl;
use App\Support\Domains\Cloudflare;
use App\Support\Domains\CustomDomains;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\WorkspaceSlugs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Settings › Domains: a Business workspace's own domain for its dashboard,
 * and for its status page (a hostname of its own, or a path on the
 * dashboard's). Then the hand-off that signs people in on it.
 */
class WorkspaceDomainController extends Controller
{
    /** How long a hand-off link works. */
    private const HANDOFF_SECONDS = 60;

    public function show(Request $request, Workspace $workspace, CustomDomains $domains): Response
    {
        // While one is waiting on DNS, each look checks with Cloudflare (at
        // most every 15 seconds), so the page can move on by itself.
        foreach ($workspace->domains()->where('state', 'pending')->get() as $pending) {
            if ($pending->checked_at === null || $pending->checked_at->lt(now()->subSeconds(15))) {
                $domains->refresh($pending);
            }
        }
        $rows = $workspace->domains()->get()->keyBy('purpose');
        $fleet = $workspace->fleetStatusPage;

        return Inertia::render('workspace/settings/Domains', [
            'workspace' => $workspace->toPageProps(),
            'allowed' => $workspace->hasCustomDomains(),
            'ready' => Cloudflare::enabled(),
            'canChange' => $workspace->allows($request->user(), WorkspacePermission::ManageSettings),
            'cnameTarget' => Cloudflare::cnameTarget(),
            'subdomain' => $workspace->subdomain(),
            'dashboard' => self::present($rows->get(WorkspaceDomain::DASHBOARD)),
            'status' => self::present($rows->get(WorkspaceDomain::STATUS)),
            // `public` and `settingsUrl` so the page can say when a domain
            // points at a page only members can open.
            'statusPages' => collect([$fleet ? [
                'slug' => $fleet->slug,
                'name' => 'Fleet page ('.($fleet->title ?: $workspace->name).')',
                'live' => $fleet->live(),
                'fleet' => true,
                'public' => (bool) $fleet->public,
                'settingsUrl' => route('workspace.settings.status-page', ['workspace' => $workspace->slug]),
            ] : null])
                ->merge($workspace->machines()->with('statusPage')->get()->filter(fn ($m) => $m->statusPage !== null)
                    ->map(fn ($m) => [
                        'slug' => $m->statusPage->slug,
                        'name' => $m->statusPage->title ?: $m->name,
                        'live' => $m->statusPage->live(),
                        'fleet' => false,
                        'public' => (bool) $m->statusPage->public,
                        'settingsUrl' => route('workspace.machines.settings', ['workspace' => $workspace->slug, 'machine' => $m]).'/status',
                    ]))
                ->filter()->values(),
            'goUrl' => route('workspace.domains.go', ['workspace' => $workspace->slug]),
            'docsUrl' => DocsUrl::page('guides', 'custom-domains'),
            // Where to get a plan with more, if anywhere (App\Support\Extensions).
            'billingUrl' => app(Extensions::class)->upgradeUrl($workspace),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, CustomDomains $domains): RedirectResponse
    {
        $this->authorizeChange($request, $workspace);
        $data = $request->validate([
            'purpose' => ['required', Rule::in([WorkspaceDomain::DASHBOARD, WorkspaceDomain::STATUS])],
            'hostname' => ['nullable', 'string', 'max:253'],
            'path' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'string', 'max:60'],
        ]);

        $hostname = self::cleanHost($data['hostname'] ?? null);
        $path = isset($data['path']) ? trim(strtolower($data['path']), '/ ') : null;
        $purpose = $data['purpose'];

        if ($purpose === WorkspaceDomain::DASHBOARD || $path === null || $path === '') {
            if ($hostname === null) {
                throw ValidationException::withMessages(['hostname' => 'Enter the domain, like ops.example.com.']);
            }
            if (($problem = CustomDomains::hostnameProblem($hostname)) !== null) {
                throw ValidationException::withMessages(['hostname' => $problem]);
            }
            $path = null;
        } else {
            $hostname = null;
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $path) !== 1 || in_array($path, CustomDomains::RESERVED_PATHS, true)) {
                throw ValidationException::withMessages(['path' => 'Use a short path of letters, numbers and dashes, like status, that the dashboard doesn’t use.']);
            }
        }
        $page = $purpose === WorkspaceDomain::STATUS ? self::page($workspace, $data['page'] ?? null) : null;

        try {
            $domains->connect($workspace, $purpose, $hostname, $path, $page);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([$hostname !== null ? 'hostname' : 'path' => $e->getMessage()]);
        }

        return back();
    }

    /** Check with Cloudflare now, and ask it to look again. */
    #[Audited]
    public function check(Request $request, Workspace $workspace, WorkspaceDomain $domain, CustomDomains $domains): RedirectResponse
    {
        abort_unless($domain->workspace_id === $workspace->id, 404);
        $this->authorizeChange($request, $workspace);
        // Going live is recorded (domain.active); a check that finds no change isn't.
        if (! $domains->refresh($domain, true)) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, WorkspaceDomain $domain, CustomDomains $domains): SymfonyResponse
    {
        abort_unless($domain->workspace_id === $workspace->id, 404);
        $this->authorizeChange($request, $workspace);
        $leaving = $domain->purpose === WorkspaceDomain::DASHBOARD && $request->attributes->get('custom_domain') === $domain->hostname;
        $domains->disconnect($domain);
        // The status path lives on the dashboard's domain: it goes with it.
        if ($domain->purpose === WorkspaceDomain::DASHBOARD) {
            $workspace->domains()->where('purpose', WorkspaceDomain::STATUS)->whereNotNull('path')->get()->each(fn ($d) => $domains->disconnect($d));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Disconnected '.($domain->hostname ?? "/{$domain->path}").'.']);

        // Removed from the domain we're on: carry on at our subdomain.
        return $leaving
            ? Inertia::location('https://'.$workspace->subdomain().'/settings/domains')
            : back();
    }

    /**
     * Send someone signed in here over to the workspace's own domain,
     * signed in there too: a one-time token, good for a minute.
     */
    public function go(Request $request, Workspace $workspace): SymfonyResponse
    {
        $host = CustomDomains::dashboardHostFor($workspace->slug);
        $to = self::localPath($request->query('to'));
        if ($host === null) {
            return redirect($to);
        }

        $token = Str::random(48);
        $carried = [];
        // And what packages carry, such as an SSO connection and session.
        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            $carried = [...$carried, ...$hooks->handoff($request)];
        }
        Cache::put("domain-handoff:{$token}", [
            'user' => $request->user()->id, 'host' => $host, 'to' => $to,
            // How they signed in, for a workspace that requires Google or SSO.
            'via' => $request->session()->get(GoogleTeamAccess::VIA),
            'hd' => $request->session()->get(GoogleTeamAccess::HD),
            ...$carried,
        ], self::HANDOFF_SECONDS);

        return Inertia::location("https://{$host}/_domain/handoff/{$token}");
    }

    /** The other end of go(): on the workspace's domain, sign in and carry on. */
    public function handoff(Request $request, string $token): RedirectResponse
    {
        $handoff = Cache::pull("domain-handoff:{$token}");
        $host = $request->attributes->get('custom_domain');
        if (! is_array($handoff) || $handoff['host'] !== $host) {
            return redirect('/login');
        }

        $user = User::query()->whereKey($handoff['user'])->first();
        if ($user !== null && $user->suspended_at === null) {
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put(array_filter([
                GoogleTeamAccess::VIA => is_string($handoff['via'] ?? null) ? $handoff['via'] : null,
                GoogleTeamAccess::HD => is_string($handoff['hd'] ?? null) ? $handoff['hd'] : null,
            ]));
            foreach (app(Extensions::class)->signInHooks() as $hooks) {
                $hooks->receiveHandoff($request, $handoff, $user);
            }
        }

        return redirect(self::localPath($handoff['to']));
    }

    /**
     * For Caddy's on-demand TLS: may it get a certificate for this hostname?
     * Only for ours, and for workspace domains waiting on or done with
     * Cloudflare. Loopback only. A self-hosted install asks for each name
     * as it's first visited, so there "ours" is only the names it serves
     * (selfHostedServes), never any subdomain someone makes up.
     */
    public function tlsCheck(Request $request): SymfonyResponse
    {
        abort_unless(in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 404);
        $domain = strtolower((string) $request->query('domain'));
        $ours = (string) config('app.domain');
        $allowed = (Edition::selfHosted() ? self::selfHostedServes($domain, $ours) : ($domain === $ours || str_ends_with($domain, ".{$ours}")))
            || WorkspaceDomain::query()->where('hostname', $domain)->whereIn('state', ['pending', 'active'])->exists();

        return response($allowed ? 'ok' : 'denied', $allowed ? 200 : 404);
    }

    /**
     * Whether a self-hosted install serves this name under its domain: the
     * domain itself, the docs and feedback sites, the status page once one
     * is set (SERVEROS_STATUS_PAGE), and its workspaces' subdomains.
     */
    private static function selfHostedServes(string $domain, string $ours): bool
    {
        if ($ours === '' || $domain === $ours) {
            return $ours !== '';
        }
        if (! str_ends_with($domain, ".{$ours}")) {
            return false;
        }
        $label = substr($domain, 0, -strlen(".{$ours}"));
        if (in_array($label, ['docs', 'feedback'], true)) {
            return true;
        }
        if ($label === 'status') {
            return filled(config('serveros.status_page'));
        }

        return preg_match(WorkspaceSlugs::PATTERN, $label) === 1
            && Workspace::query()->where('slug', $label)->exists();
    }

    private function authorizeChange(Request $request, Workspace $workspace): void
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $workspace->hasCustomDomains()) {
            throw ValidationException::withMessages(['hostname' => 'Your own domains come with Business.']);
        }
    }

    /**
     * A domain as the settings page shows it.
     *
     * @return array<string, mixed>|null
     */
    private static function present(?WorkspaceDomain $domain): ?array
    {
        if ($domain === null) {
            return null;
        }
        $dashboard = $domain->workspace->domains()->where('purpose', WorkspaceDomain::DASHBOARD)->value('hostname');

        return [
            'id' => $domain->id,
            'hostname' => $domain->hostname,
            'path' => $domain->path,
            'page' => $domain->status_page_slug,
            'state' => $domain->state,
            'records' => $domain->verification['records'] ?? [],
            'error' => $domain->error,
            'checkedAt' => $domain->checked_at?->toIso8601String(),
            'url' => match (true) {
                $domain->hostname !== null => "https://{$domain->hostname}",
                $dashboard !== null => "https://{$dashboard}/{$domain->path}",
                default => null,
            },
        ];
    }

    /** A status page of this workspace, by slug; null for the fleet page. */
    private static function page(Workspace $workspace, ?string $slug): ?string
    {
        $fleet = $workspace->fleetStatusPage?->slug;
        if ($slug === null || $slug === '' || $slug === $fleet) {
            if ($fleet === null) {
                throw ValidationException::withMessages(['page' => 'Set up the fleet status page first, in Settings › Status page, or pick a machine’s page.']);
            }

            return null;
        }
        $mine = $workspace->machines()->whereHas('statusPage', fn ($q) => $q->where('slug', $slug))->exists();
        if (! $mine) {
            throw ValidationException::withMessages(['page' => 'That status page isn’t in this workspace.']);
        }

        return $slug;
    }

    /** What was typed, as a bare hostname: no scheme, path, port or dot at the end. */
    private static function cleanHost(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = trim((string) strtok($value, '/?#'), '. ');
        $value = (string) preg_replace('/:\d+$/', '', $value);

        return $value === '' ? null : $value;
    }

    /** A path on this site, never somewhere else. */
    private static function localPath(mixed $to): string
    {
        $to = is_string($to) ? $to : '/';

        return str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/';
    }
}
