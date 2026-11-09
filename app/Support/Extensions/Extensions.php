<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use App\Support\InstallPing;
use App\Support\Mcp\OAuthAudience;
use App\Support\Mcp\WorkspaceAudience;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where packages plug into the panel. Each registers from its service
 * provider; the panel never refers to them.
 *
 * - Routes go into a slot the panel loads in the right place: WORKSPACE
 *   inside the workspace's subdomain group (its middleware and {workspace}
 *   binding included), SIGN_IN with the panel's own sign-in routes (ahead
 *   of the docs, feedback and workspace hosts), WEB at the end of
 *   routes/web.php, API at the end of routes/api.php, ADMIN at the end of
 *   the staff admin group (prefix admin, names admin.*, can:viewAdmin),
 *   MCP with the OAuth server's routes in routes/mcp.php (api, McpCors),
 *   and SUBDOMAINS for sites on their own subdomain (docs., feedback.),
 *   ahead of the workspace subdomains.
 * - Middleware goes into a slot that keeps its place in a list
 *   (addMiddleware): WORKSPACE_SIGN_IN in the workspace group's sign-in
 *   checks, after Google sign-in and before two-factor; WORKSPACE in the
 *   workspace group's, after two-factor and before the visit is recorded;
 *   WEB in the web group's, after AccountGuard; WEB_END last in the web
 *   group's; PASSWORD_SIGN_IN in Fortify's sign-in steps, before the code
 *   prompt.
 * - Sign-in and sign-out can ask a package (addSignInHooks, SignInHooks).
 * - Signing up can tell a package about the new account, and ask it where
 *   the account came from (addSignUpHooks, SignUpHooks).
 * - Sidebar sections (NavItem) and settings pages (SettingsItem) are shared
 *   with the page for the current workspace, filtered by their visible check.
 * - The upgrade link (UpgradeLink): where the panel's "upgrade" and "choose
 *   a plan" prompts go, shared as the upgrade prop. None means no link.
 * - Routes an admin viewing as someone can't use, with what they're told.
 * - What a trial asks of a new workspace before it starts (TrialCards,
 *   bound in the container; NoTrialCards when no package says).
 * - A panel page can carry props a package adds (addPageProps), and the
 *   members list extra fields on each member (addMemberProps); the page
 *   shows them through its <ExtensionSlot>s, components the package keeps
 *   in resources/js/slots/{slot name}/.
 * - Pages need nothing here: the panel's page resolver also looks in
 *   vendor/serveroshq/{package}/resources/js/pages. See docs/extensions.md.
 * - A website a package draws with its own app (MarketingSite): its pages'
 *   routes and their root view.
 * - Errors on a package's own endpoints can be answered in their own shape
 *   (addExceptionRenderer), ahead of the panel's API errors and error pages.
 * - A self-hosted install's daily install ping can carry a package's own
 *   numbers or yes/no answers (addInstallStat), like whether it's licensed.
 * - MCP OAuth tokens can be for a package's own MCP server, not only a
 *   workspace's (addOAuthAudience, App\Support\Mcp\OAuthAudience).
 * - Route names whose URLs stay on our domain when the page is on a
 *   workspace's own domain (keepOnMainDomain), like a package's MCP server.
 * - The staff admin's sidebar (AdminNavItem): the panel's own pages and the
 *   packages', shared with admin pages as the adminNav prop.
 * - What a staff action in the admin audit log can be about, besides the
 *   panel's own models (addAuditTarget, App\Models\AdminAction).
 * - Props every page gets, after the panel's own (addSharedProps), like the
 *   counts on a package's admin sidebar badges.
 *
 * Migrations, config, commands and schedules use Laravel's own package
 * tools (loadMigrationsFrom, mergeConfigFrom, commands, callAfterResolving).
 */
final class Extensions
{
    public const string WEB = 'web';

    public const string WORKSPACE = 'workspace';

    public const string API = 'api';

    /** Inside the admin group: prefix admin, names admin.*, can:viewAdmin. */
    public const string ADMIN = 'admin';

    /** With the panel's own sign-in routes, on every host, ahead of the docs, feedback and workspace hosts. */
    public const string SIGN_IN = 'sign-in';

    /** In routes/mcp.php with the OAuth server's routes: the api group and McpCors, every host. */
    public const string MCP = 'mcp';

    /** Middleware: the workspace group's sign-in checks, after Google sign-in and before two-factor. */
    public const string WORKSPACE_SIGN_IN = 'workspace-sign-in';

    /** Middleware: Fortify's password sign-in steps, before the code prompt. */
    public const string PASSWORD_SIGN_IN = 'password-sign-in';

    /**
     * Sites on their own subdomain of the main domain (docs., feedback.),
     * in routes/web.php ahead of the workspace subdomains, so that name
     * never reaches a workspace. The web middleware group, no more.
     */
    public const string SUBDOMAINS = 'subdomains';

    /** Middleware: last in the web group, after the panel's own (WEB runs after AccountGuard). */
    public const string WEB_END = 'web-end';

    /** @var array<string, list<Closure(): void>> */
    private array $routes = [];

    /** @var array<string, NavItem> */
    private array $navItems = [];

    /** @var array<string, SettingsItem> */
    private array $settingsItems = [];

    private ?UpgradeLink $upgrade = null;

    private ?MarketingSite $marketingSite = null;

    /** @var array<string, string> route name => why it's off limits */
    private array $offLimitsWhileViewingAs = [];

    /** @var array<string, list<class-string>> */
    private array $middleware = [];

    /** @var list<SignInHooks> */
    private array $signInHooks = [];

    /** @var list<SignUpHooks> */
    private array $signUpHooks = [];

    /** @var array<string, list<Closure(Request, Workspace|null): array<string, mixed>>> */
    private array $pageProps = [];

    /** @var list<Closure(Workspace, list<int>): array<int, array<string, mixed>>> */
    private array $memberProps = [];

    /** @var list<Closure(Throwable, Request): (Response|null)> */
    private array $exceptionRenderers = [];

    /** @var array<string, OAuthAudience> by key, in the order packages added them */
    private array $oauthAudiences = [];

    private ?WorkspaceAudience $workspaceAudience = null;

    /** @var list<string> */
    private array $mainDomainRoutes = [];

    /** @var array<string, AdminNavItem> */
    private array $adminNav = [];

    /** @var array<string, array{class: class-string<Model>, label: Closure|null}> */
    private array $auditTargets = [];

    /** @var list<Closure(Request): array<string, mixed>> */
    private array $sharedProps = [];

    /**
     * Checked when the ping is built: a package's check could answer anything.
     *
     * @var array<string, Closure(): mixed>
     */
    private array $installStats = [];

    /** @var (Closure(Workspace): string)|null */
    private ?Closure $poweredBy = null;

    /** @var array<string, string> */
    private array $accountEvents = [];

    /** @var array<class-string<Model>, array<string, string>> */
    private array $modelCasts = [];

    /**
     * Where a page's source is, for @vite to preload it from the manifest:
     * the panel's own file, or else the package's that has it (packages name
     * their pages like the panel's). The panel's path if neither has it, so
     * a missing page fails the way it always has.
     *
     * @param  'pages'|'marketing/pages'  $folder
     */
    public static function pageEntry(string $component, string $folder, string $extension): string
    {
        $own = "resources/js/{$folder}/{$component}.{$extension}";

        if (is_file(base_path($own))) {
            return $own;
        }

        $base = base_path().DIRECTORY_SEPARATOR;
        foreach (glob(base_path("vendor/serveroshq/*/resources/js/{$folder}/{$component}.{$extension}")) ?: [] as $path) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)));
        }

        return $own;
    }

    /**
     * Routes for a slot, registered when the panel loads that slot.
     *
     * @param  Closure(): void  $register
     */
    public function routes(string $slot, Closure $register): void
    {
        if (! in_array($slot, [self::WEB, self::WORKSPACE, self::API, self::ADMIN, self::SIGN_IN, self::MCP, self::SUBDOMAINS], true)) {
            throw new InvalidArgumentException("There's no route slot called {$slot}.");
        }

        $this->routes[$slot][] = $register;
    }

    /** Called by the route files, where the slot's routes belong. */
    public function loadRoutes(string $slot): void
    {
        foreach ($this->routes[$slot] ?? [] as $register) {
            $register();
        }
    }

    /**
     * Middleware for a slot, run in its place in the slot's list, in the
     * order packages add it: WORKSPACE_SIGN_IN (the workspace group's sign-in
     * checks, after Google sign-in and before two-factor), WORKSPACE (the
     * workspace group, after two-factor and before the visit is recorded),
     * WEB (every web request, after AccountGuard), WEB_END (every web
     * request, last in the web group) or PASSWORD_SIGN_IN (a Fortify sign-in
     * step).
     *
     * @param  class-string  $middleware
     */
    public function addMiddleware(string $slot, string $middleware): void
    {
        if (! in_array($slot, [self::WEB, self::WEB_END, self::WORKSPACE, self::WORKSPACE_SIGN_IN, self::PASSWORD_SIGN_IN], true)) {
            throw new InvalidArgumentException("There's no middleware slot called {$slot}.");
        }

        $this->middleware[$slot][] = $middleware;
    }

    /**
     * The middleware packages put in this slot.
     *
     * @return list<class-string>
     */
    public function middleware(string $slot): array
    {
        return $this->middleware[$slot] ?? [];
    }

    /**
     * Something else MCP OAuth tokens can be for, like a package's own MCP
     * server. Its key is stored with every code and token it gets, so it
     * can't be the workspace audience's.
     */
    public function addOAuthAudience(OAuthAudience $audience): void
    {
        if ($audience->key() === $this->workspaceAudience()->key()) {
            throw new InvalidArgumentException("{$audience->key()} is the panel's own OAuth audience.");
        }

        $this->oauthAudiences[$audience->key()] = $audience;
    }

    /**
     * Every audience, by key: a workspace's MCP server first, then the
     * packages', in the order they were added.
     *
     * @return array<string, OAuthAudience>
     */
    public function oauthAudiences(): array
    {
        return [$this->workspaceAudience()->key() => $this->workspaceAudience(), ...$this->oauthAudiences];
    }

    /** The audience stored as this key, or null when nothing installed knows it. */
    public function oauthAudience(string $key): ?OAuthAudience
    {
        return $this->oauthAudiences()[$key] ?? null;
    }

    private function workspaceAudience(): WorkspaceAudience
    {
        return $this->workspaceAudience ??= new WorkspaceAudience;
    }

    /**
     * Routes whose URLs stay on our own domain when they're made on a
     * workspace's own domain (App\Support\Domains\CustomDomains), like a
     * package's MCP server: what apps were set up with.
     *
     * @param  list<string>  $routeNamePrefixes  Route names, or the start of them, like "tickets.mcp".
     */
    public function keepOnMainDomain(array $routeNamePrefixes): void
    {
        $this->mainDomainRoutes = array_values(array_unique([...$this->mainDomainRoutes, ...$routeNamePrefixes]));
    }

    /** @return list<string> */
    public function mainDomainRoutes(): array
    {
        return $this->mainDomainRoutes;
    }

    /**
     * A page in the staff admin's sidebar (resources/js/layouts/AdminLayout.vue).
     * The panel adds the admin pages it serves itself; a package adds its own.
     */
    public function addAdminNavItem(AdminNavItem $item): void
    {
        $this->adminNav[$item->key] = $item;
    }

    /**
     * The admin sidebar for this person: each group (AdminNavItem::GROUPS,
     * in that order) with the pages in it they may open, in their places;
     * groups with none left out, so it's empty when there's nothing here
     * for them (or no admin pages at all).
     *
     * @return list<array{key: string, items: list<array<string, mixed>>}>
     */
    public function adminNavFor(?User $user): array
    {
        $groups = [];
        foreach (AdminNavItem::GROUPS as $group) {
            $items = array_filter($this->adminNav, fn (AdminNavItem $item) => $item->group === $group && $item->visibleTo($user));
            if ($items !== []) {
                $groups[] = ['key' => $group, 'items' => array_map(fn (AdminNavItem $item) => $item->toArray(), AdminNavItem::arrange(array_values($items)))];
            }
        }

        return $groups;
    }

    /**
     * A model of a package's that a staff action can be about, in the admin
     * audit log (App\Models\AdminAction): the short name stored with the
     * action (target_type, so never change it once there are rows) and what
     * to call one in the history, as it is when the action happens.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  (Closure(TModel): string)|null  $label  null for "#id"
     */
    public function addAuditTarget(string $type, string $class, ?Closure $label = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) !== 1) {
            throw new InvalidArgumentException("An audit target's name is snake_case, like blog_post: {$type} isn't.");
        }

        $this->auditTargets[$type] = ['class' => $class, 'label' => $label];
    }

    /**
     * The audit targets packages add, by the name stored with them.
     *
     * @return array<string, array{class: class-string<Model>, label: Closure|null}>
     */
    public function auditTargets(): array
    {
        return $this->auditTargets;
    }

    /**
     * Props every page gets, after the panel's own shared props (in
     * HandleInertiaRequests), so a package's never move the panel's. Give a
     * closure as a prop's value to work it out only when it's sent (and not
     * on a partial reload that doesn't ask for it). A name the panel shares
     * itself is the panel's.
     *
     * @param  Closure(Request): array<string, mixed>  $props
     */
    public function addSharedProps(Closure $props): void
    {
        $this->sharedProps[] = $props;
    }

    /**
     * The props packages share with every page, for this request.
     *
     * @return array<string, mixed>
     */
    public function sharedPropsFor(Request $request): array
    {
        $props = [];
        foreach ($this->sharedProps as $add) {
            $props = [...$props, ...$add($request)];
        }

        return $props;
    }

    /** What a package does when people sign in and out (SignInHooks has each). */
    public function addSignInHooks(SignInHooks $hooks): void
    {
        $this->signInHooks[] = $hooks;
    }

    /** @return list<SignInHooks> */
    public function signInHooks(): array
    {
        return $this->signInHooks;
    }

    /** What a package does when someone signs up (SignUpHooks has each). */
    public function addSignUpHooks(SignUpHooks $hooks): void
    {
        $this->signUpHooks[] = $hooks;
    }

    /** @return list<SignUpHooks> */
    public function signUpHooks(): array
    {
        return $this->signUpHooks;
    }

    /** Tells every package about an account that was just made, before its workspace is. */
    public function signedUp(Request $request, User $user): void
    {
        foreach ($this->signUpHooks as $hooks) {
            $hooks->signedUp($request, $user);
        }
    }

    /** Where a new account came from: the first package's answer, or null. */
    public function signUpSource(User $user): ?string
    {
        foreach ($this->signUpHooks as $hooks) {
            if (($source = $hooks->source($user)) !== null) {
                return $source;
            }
        }

        return null;
    }

    public function addNavItem(NavItem $item): void
    {
        $this->navItems[$item->key] = $item;
    }

    public function addSettingsItem(SettingsItem $item): void
    {
        $this->settingsItems[$item->key] = $item;
    }

    /**
     * The sidebar sections packages add that this person may see here.
     *
     * @return list<array<string, mixed>>
     */
    public function navFor(Workspace $workspace, ?User $user): array
    {
        return array_values(array_map(
            fn (NavItem $item) => $item->toArray(),
            array_filter($this->navItems, fn (NavItem $item) => $item->visibleTo($workspace, $user)),
        ));
    }

    /**
     * The settings pages packages add that this person may see here.
     *
     * @return list<array<string, mixed>>
     */
    public function settingsFor(Workspace $workspace, ?User $user): array
    {
        return array_values(array_map(
            fn (SettingsItem $item) => $item->toArray(),
            array_filter($this->settingsItems, fn (SettingsItem $item) => $item->visibleTo($workspace, $user)),
        ));
    }

    /** Where the panel’s upgrade prompts go (one link; the last set wins, and null takes it away). */
    public function setUpgradeLink(?UpgradeLink $link): void
    {
        $this->upgrade = $link;
    }

    public function upgradeLink(): ?UpgradeLink
    {
        return $this->upgrade;
    }

    /** The website a package draws with its own app: its pages' routes and root view. */
    public function setMarketingSite(MarketingSite $site): void
    {
        $this->marketingSite = $site;
    }

    public function marketingSite(): ?MarketingSite
    {
        return $this->marketingSite;
    }

    /**
     * The upgrade link for this workspace's pages, or null for none.
     *
     * @return array{href: string, label: string}|null
     */
    public function upgradeFor(Workspace $workspace): ?array
    {
        return $this->upgrade?->toArray($workspace);
    }

    /** The upgrade link in full, on the workspace's subdomain, or null for none. */
    public function upgradeUrl(Workspace $workspace): ?string
    {
        return $this->upgrade?->url($workspace);
    }

    /**
     * Routes an admin viewing as someone can't use (AccountGuard), and the
     * message they get instead.
     *
     * @param  list<string>  $routes  Route names.
     */
    public function offLimitsWhileViewingAs(array $routes, string $message): void
    {
        foreach ($routes as $route) {
            $this->offLimitsWhileViewingAs[$route] = $message;
        }
    }

    /** Why that route is off limits while viewing as someone, or null when a package didn't say. */
    public function offLimitsMessage(?string $route): ?string
    {
        return $route === null ? null : ($this->offLimitsWhileViewingAs[$route] ?? null);
    }

    /**
     * Props a package adds to a panel page that asks for them (the page's
     * controller merges pagePropsFor), like Members' provisioning settings.
     * The workspace is null on pages outside one, like the sign-in page.
     *
     * @param  string  $component  The Inertia page, like "workspace/settings/Members".
     * @param  Closure(Request, Workspace|null): array<string, mixed>  $props
     */
    public function addPageProps(string $component, Closure $props): void
    {
        $this->pageProps[$component][] = $props;
    }

    /**
     * The props packages add to this page, for this request (and the
     * workspace it's in, if any).
     *
     * @return array<string, mixed>
     */
    public function pagePropsFor(string $component, Request $request, ?Workspace $workspace = null): array
    {
        $props = [];
        foreach ($this->pageProps[$component] ?? [] as $add) {
            $props = [...$props, ...$add($request, $workspace)];
        }

        return $props;
    }

    /**
     * Fields a package adds to each member on the members list, by user id,
     * like whether the identity provider manages them.
     *
     * @param  Closure(Workspace, list<int>): array<int, array<string, mixed>>  $props
     */
    public function addMemberProps(Closure $props): void
    {
        $this->memberProps[] = $props;
    }

    /**
     * The fields packages add for these members, by user id.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function memberPropsFor(Workspace $workspace, array $userIds): array
    {
        $props = [];
        foreach ($this->memberProps as $add) {
            foreach ($add($workspace, $userIds) as $id => $fields) {
                $props[$id] = [...($props[$id] ?? []), ...$fields];
            }
        }

        return $props;
    }

    /**
     * Answer errors on a package's own endpoints in their own shape, like
     * SCIM's: return a response for the requests it's for, null for the rest.
     * Asked before the panel's own error handling.
     *
     * @param  Closure(Throwable, Request): (Response|null)  $render
     */
    public function addExceptionRenderer(Closure $render): void
    {
        $this->exceptionRenderers[] = $render;
    }

    /** The first package's answer to this error, or null to carry on as usual. */
    public function renderException(Throwable $e, Request $request): ?Response
    {
        foreach ($this->exceptionRenderers as $render) {
            if (($response = $render($e, $request)) !== null) {
                return $response;
            }
        }

        return null;
    }

    /**
     * A number or a yes/no a package adds to the install ping, the anonymous
     * daily ping a self-hosted install sends (App\Support\InstallPing), like
     * "licensed". Only a whole number (0 or more) or a boolean is
     * sent: anything else, or a check that throws, is left out. The name is
     * snake_case and can't be one of the ping's own fields, apart from the
     * ones packages answer (InstallPing::PACKAGE_FIELDS).
     *
     * @param  Closure(): (int|bool)  $value
     */
    public function addInstallStat(string $name, Closure $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException("An install stat's name is snake_case, like licensed: {$name} isn't.");
        }
        if (in_array($name, InstallPing::FIELDS, true) && ! in_array($name, InstallPing::PACKAGE_FIELDS, true)) {
            throw new InvalidArgumentException("{$name} is one of the install ping's own fields.");
        }

        $this->installStats[$name] = $value;
    }

    /**
     * The install stats packages add, each checked now. A check that throws,
     * or answers anything but a whole number of 0 or more or a boolean, is
     * left out, so the ping never carries anything else.
     *
     * @return array<string, int|bool>
     */
    public function installStats(): array
    {
        $stats = [];
        foreach ($this->installStats as $name => $value) {
            try {
                $answer = $value();
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if (is_bool($answer) || (is_int($answer) && $answer >= 0)) {
                $stats[$name] = $answer;
            }
        }

        return $stats;
    }

    /**
     * Where the "Sent with ServerOS" and "Powered by ServerOS" links on a
     * workspace's status pages and workflow emails go: a package's address
     * for the workspace, in place of the site's own.
     *
     * @param  Closure(Workspace): string  $url
     */
    public function setPoweredByLink(Closure $url): void
    {
        $this->poweredBy = $url;
    }

    /** That link for a workspace: the package's, or the site's own address. */
    public function poweredByUrl(Workspace $workspace): string
    {
        return $this->poweredBy !== null
            ? ($this->poweredBy)($workspace)
            : rtrim((string) config('app.url'), '/').'/';
    }

    /**
     * How a package's own account event reads (App\Models\AccountEvent,
     * on Settings › Security and the admin pages): its English text, said
     * to the person, translated when shown.
     */
    public function addAccountEvent(string $event, string $text): void
    {
        $this->accountEvents[$event] = $text;
    }

    /** A package's text for an account event, if one gave it. */
    public function accountEventText(string $event): ?string
    {
        return $this->accountEvents[$event] ?? null;
    }

    /**
     * Casts for a package's own columns on one of the panel's models, like
     * dates a package keeps on workspaces. The model's
     * casts() puts them first, so the panel's own always win (Workspace's
     * does; add the call to another model's when a package needs it).
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $casts
     */
    public function addModelCasts(string $model, array $casts): void
    {
        $this->modelCasts[$model] = [...$this->modelCasts[$model] ?? [], ...$casts];
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public function modelCasts(string $model): array
    {
        return $this->modelCasts[$model] ?? [];
    }
}
