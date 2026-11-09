<?php

namespace App\Http\Middleware;

use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Brand;
use App\Support\CloudOffer;
use App\Support\Docs\DocsUrl;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\MarketingSite;
use App\Support\GitHub\GitHubApp;
use App\Support\Mcp\McpUsage;
use App\Support\SetupGuide;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Middleware;
use ServerOS\Saas\Changelog\ChangelogFeed;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Whether this page goes to the SSR server first: a package's website
     * page (MarketingSite, serveros.com's React site) in English. The Vue
     * panel and admin stay client-rendered, and so do other languages,
     * because the site's t() translates lists once when their file loads,
     * which on a long-running SSR server would be one language for every
     * visitor. AppServiceProvider asks this when each page is drawn.
     */
    public function shouldServerRender(Request $request): bool
    {
        return $this->isMarketing($request) && app()->getLocale() === 'en';
    }

    /**
     * A package's website pages get its root view (and their own asset
     * version, below), so a visit from one app to the other's pages is a
     * full page load rather than an Inertia swap the other app can't render.
     */
    public function rootView(Request $request): string
    {
        $site = $this->marketingSite($request);

        return $site === null ? $this->rootView : $site->rootView;
    }

    protected function isMarketing(Request $request): bool
    {
        return $this->marketingSite($request) !== null;
    }

    /** The package's website, when this page is one of its pages. */
    private function marketingSite(Request $request): ?MarketingSite
    {
        $site = app(Extensions::class)->marketingSite();

        return $site !== null && $site->routes !== [] && $request->routeIs(...$site->routes) ? $site : null;
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        $version = parent::version($request);

        return $this->isMarketing($request) ? 'marketing-'.$version : $version;
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $shared = [
            ...parent::share($request),
            'name' => config('app.name'),
            // True once, on the first workspace page after signing up (the
            // dashboard, once the welcome flow is done).
            'celebrate' => fn () => $request->routeIs('workspace.*') && ! $request->routeIs('workspace.welcome.*') && $request->user()?->hasVerifiedEmail()
                ? (bool) $request->session()->pull('celebrate_trial', false)
                : false,
            'appUrl' => rtrim((string) config('app.url'), '/'),
            // The host this page was asked for, when it's ours (docs., status.,
            // feedback.): the server renderer builds links from it, as the
            // browser does from its address bar.
            'siteOrigin' => $this->siteOrigin($request),
            // Open-source mode: the marketing site's self-hosting and open-source parts.
            'openSource' => (bool) config('serveros.open_source'),
            // Whether the site links to ServerOS Cloud: once it's open, and for staff before.
            // Cloud is in the serveros.com package; this moves with the website.
            'cloudOpen' => fn () => CloudOffer::forMarketing($request->user()) !== null,
            // Our own status page, for the dashboard's footer, once it's set up.
            'statusUrl' => filled(config('serveros.status_page')) ? 'https://status.'.config('app.domain') : null,
            // Who runs this install and where its terms are, for the footers (App\Support\Brand).
            'brand' => Brand::shared(),
            // Where the docs are: this install's docs site, or serveros.com's.
            'docsUrl' => DocsUrl::home(),
            // The feedback board: this install's, or serveros.com's.
            'feedbackUrl' => Router::has('feedback.index') ? rtrim(route('feedback.index'), '/') : 'https://feedback.serveros.com',
            // Plan prices in the visitor's currency (Cloudflare's country,
            // or ?currency=), the same amounts checkout charges.
            'pricing' => function () use ($request): array {
                $currency = Currency::forVisitor($request);

                return [
                    'currency' => $currency->value,
                    'symbol' => $currency->symbol(),
                    'plans' => collect(Plan::cases())->mapWithKeys(fn (Plan $plan) => [$plan->value => [
                        'monthly' => $plan->monthlyPrice($currency),
                        'annual' => $plan->annualPrice($currency),
                    ]])->all(),
                    'mcpPack' => McpUsage::packPrice($currency),
                ];
            },
            'promo' => filled(config('serveros.promo.code'))
                ? ['code' => (string) config('serveros.promo.code'), 'offer' => (string) config('serveros.promo.offer')]
                : null,
            'auth' => [
                'user' => $request->user(),
            ],
            'workspaces' => fn () => $request->user()?->workspaces()
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'host' => $workspace->host(),
                    'url' => $workspace->url(),
                    'switchUrl' => route('workspace.switch', ['workspace' => $workspace->slug]),
                    'logoUrl' => $workspace->logoUrl(),
                    'pinnedAt' => $workspace->pivot->pinned_at === null ? null : (string) $workspace->pivot->pinned_at,
                ])
                ->all() ?? [],
            // "What's new" in the panel: the latest entries and how many they haven't seen.
            'changelog' => fn () => $request->routeIs('workspace.*') && $request->user() !== null && class_exists(ChangelogFeed::class)
                ? ChangelogFeed::for($request->user())
                : null,
            // What the private packages add to this workspace's sidebar and
            // settings, for this person (App\Support\Extensions).
            'extensions' => function () use ($request): ?array {
                $workspace = self::workspacePage($request);

                if ($workspace === null) {
                    return null;
                }

                $extensions = app(Extensions::class);

                return [
                    'nav' => $extensions->navFor($workspace, $request->user()),
                    'settings' => $extensions->settingsFor($workspace, $request->user()),
                ];
            },
            // Where the dashboard's upgrade and "choose a plan" prompts go,
            // as {href, label}: a package's (serveros.com: Billing), or
            // null for no link (App\Support\Extensions).
            'upgrade' => function () use ($request): ?array {
                $workspace = self::workspacePage($request);

                return $workspace === null ? null : app(Extensions::class)->upgradeFor($workspace);
            },
            'workspaceAccess' => function () use ($request): ?array {
                $workspace = $request->route('workspace');
                $role = $workspace instanceof Workspace && $request->user()
                    ? $workspace->roleOf($request->user())
                    : null;

                return $role === null ? null : [
                    'role' => $role->value,
                    'permissions' => array_map(fn ($permission) => $permission->value, $role->permissions()),
                ];
            },
            // An admin viewing as this person: who, so the bar can say so.
            'viewingAs' => fn () => Impersonation::active($request) && $request->user()
                ? ['name' => $request->user()->name, 'email' => $request->user()->email, 'admin' => Impersonation::impersonator($request)?->name]
                : null,
            // The setup guide in the top bar, until it's done or hidden.
            'setupGuide' => function () use ($request): ?array {
                $workspace = $request->route('workspace');

                return $request->routeIs('workspace.*') && $workspace instanceof Workspace && $request->user() !== null
                    ? SetupGuide::for($workspace, $request->user())
                    : null;
            },
            // The language the page is in, and the ones that can be chosen.
            'locale' => app()->getLocale(),
            'locales' => config('app.locales'),
            // Whether private GitHub repositories can be connected.
            'githubApp' => GitHubApp::enabled(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // What staff pages someone may open: all of /admin, or only Sales
            // (reps), when there's a Sales page (it's serveros.com's).
            'staff' => fn () => [
                'admin' => $request->user()?->can('viewAdmin') === true,
                'sales' => $request->user()?->can('viewSales') === true && Router::has('admin.sales.index'),
            ],
            // The admin sidebar, on admin pages only: the panel's own pages and
            // the packages' that this person may open (App\Support\Extensions\AdminNavItem).
            ...($request->routeIs('admin.*') ? ['adminNav' => fn () => app(Extensions::class)->adminNavFor($request->user())] : []),
        ];

        // The packages' own (App\Support\Extensions), after the panel's
        // and never over one, like serveros.com's admin badge counts.
        return [...$shared, ...array_diff_key(app(Extensions::class)->sharedPropsFor($request), $shared)];
    }

    /**
     * The scheme and host of this request when it's the site or one of its
     * subdomains, otherwise the site's own address (never a stranger's Host).
     */
    protected function siteOrigin(Request $request): string
    {
        $site = rtrim((string) config('app.url'), '/');
        $domain = (string) config('app.domain');
        $host = $request->getHost();

        if ($domain === '' || ($host !== $domain && ! str_ends_with($host, '.'.$domain))) {
            return $site;
        }

        return (parse_url($site, PHP_URL_SCHEME) ?: 'https').'://'.$request->getHttpHost();
    }

    /**
     * The workspace a page is in: any route in the workspace's subdomain
     * group, named or not (the tab routes, like machines/{machine}/{tab},
     * have no name), so what packages add shows on every workspace page.
     */
    private static function workspacePage(Request $request): ?Workspace
    {
        $route = $request->route();

        if (! $route instanceof Route || ! str_starts_with((string) $route->getDomain(), '{workspace}.')) {
            return null;
        }

        $workspace = $route->parameter('workspace');

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
