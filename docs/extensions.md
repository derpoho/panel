# Extending the panel from a package

The panel can be extended by Laravel packages installed under `vendor/serveroshq/`, without the panel knowing about them. serveros.com runs it this way, with two packages that aren't open source:

- **serveroshq/panel-ee**: the paid features, on serveros.com and on licensed self-hosted installs.
- **serveroshq/serveros.com**: what only serveros.com runs (billing, Cloud, licensing, its own admin pages, the website).

The panel never refers to a package. Each one registers itself from its service provider through `App\Support\Extensions\Extensions`, and everything below works the same for yours.

## What a workspace may use

Ask the workspace, never the plan: `$workspace->hasFeature(Feature::Sso)`, `$workspace->limitOf(Limit::Machines)`, `$workspace->isActive()`. `App\Support\Entitlements` answers from the plan on serveros.com (`SERVEROS_EDITION=cloud`) and from the edition on a self-hosted install (`SERVEROS_EDITION=self-hosted`).

On a self-hosted install with panel-ee, a workspace's ServerOS Business license adds to the free edition: panel-ee puts its `LicenseEntitlements` in front of `SelfHostedEntitlements` (with `$app->extend`), adding the key's features and machine allowance. Keys are signed by serveros.com's license server (in the serveros.com package) and checked offline; both packages' READMEs explain how.

## Routes

```php
use App\Support\Extensions\Extensions;

$this->app->make(Extensions::class)->routes(Extensions::WORKSPACE, function () {
    Route::get('settings/sso', [SsoController::class, 'show'])->name('workspace.settings.sso');
});
```

| Slot                     | Loaded                                                     | You get                                                                                                                                              |
| ------------------------ | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Extensions::WORKSPACE`  | Inside the workspace's subdomain group in `routes/web.php` | Its middleware (signed in, verified, can view the workspace, SSO, two-factor and so on) and the `{workspace}` binding. Name routes `workspace.*`.    |
| `Extensions::SIGN_IN`    | With the panel's own sign-in routes in `routes/web.php`    | Every host, with the `web` middleware group, ahead of the docs, feedback and workspace hosts (panel-ee's `sso/*`).                                   |
| `Extensions::WEB`        | At the end of `routes/web.php`                             | The main domain, with the `web` middleware group.                                                                                                    |
| `Extensions::API`        | At the end of `routes/api.php`                             | The `api` prefix and middleware group. Add `AuthenticateApiKey` yourself for the public API.                                                         |
| `Extensions::ADMIN`      | At the end of the admin group in `routes/web.php`          | Prefix `admin`, names `admin.*`, signed in, verified and `can:viewAdmin`.                                                                            |
| `Extensions::MCP`        | With the OAuth server's routes in `routes/mcp.php`         | The `api` group and `McpCors`, every host (a package's own OAuth metadata).                                                                          |
| `Extensions::SUBDOMAINS` | In `routes/web.php`, ahead of the workspace subdomains     | The `web` middleware group. For sites on their own subdomain (`Route::domain('docs.'.config('app.domain'))`), so the name never reaches a workspace. |

Register from the provider's `boot()`: the route files load after every provider has booted. Not from `register()`: package providers register before the panel's AppServiceProvider makes Extensions a singleton, so what they add there is lost. Use controllers, not closures, so `route:cache` keeps working.

## Pages

Put Vue pages in the package's `resources/js/pages/`, named like the panel's own: `resources/js/pages/workspace/settings/Sso.vue` renders as `workspace/settings/Sso` and gets the same layout a page of that name gets in the panel. The panel's page lookup (`resources/js/lib/pages.ts`) looks in `vendor/serveroshq/*` as well as the panel. If a package and the panel both have a page, the panel's wins.

Import panel code with `@/` as usual, and the package's own files with relative paths. Tailwind scans `vendor/serveroshq/*/resources/js`, and the Vite dev server may serve packages Composer linked from a folder next to the panel. `npm run types:check` checks packages' Vue pages with the panel's `tsconfig.json` and their React code (`resources/js/marketing`) with `tsconfig.react.json`.

## A website

The panel has no website of its own: without a package, `/` opens on the dashboard (or on signing in, or on making an install's first account, `App\Support\Edition::home`) and every page is the panel's. serveros.com's package brings serveros.com's website, a React app with its own Inertia root, and the panel builds and serves it:

- **Its pages and root view:** `$extensions->setMarketingSite(new MarketingSite(rootView: 'saas::marketing', routes: ['home', 'pricing', 'legal.*', ...]))`. Pages on those routes (`routeIs` patterns) render in that Blade view with their own asset version (so going between the panel and the site is a full page load), and only they go to the SSR server, in English. The error page on the main domain and its `docs.` and `feedback.` subdomains uses the view too. The package registers its own `/` (named `home`), and the panel's leaves it alone.
- **Its app:** `resources/js/marketing/app.tsx` (the browser) and `resources/js/marketing/ssr.tsx` (the SSR bundle, `npm run build:ssr`). `vite.config.ts` finds them in `vendor/serveroshq/*` and adds them as entries; without them there's no React entry and no SSR bundle. The site looks up its own pages; the Blade view loads `resources/css/app.css`, the app, and `Extensions::pageEntry($component, 'marketing/pages', 'tsx')`.
- **Its styles:** `resources/css/app.css` has `@package-css 'file.css';` rules, each where the styles belong among the panel's. The build turns each into an import of the package's `resources/css/file.css` when one has it, or drops it (`packageStyles()` in `vite.config.ts`, ahead of Tailwind), so the compiled stylesheet is the one serveros.com always had. It isn't an `@import` so that tools reading `app.css` on their own (the formatter's class sorting) don't stop at a missing file. serveros.com's are `marketing-theme.css` (its colours) and `site-*.css`. Add a rule there when a package needs one.
- **Its public files:** a package's `public/` folder (images, videos, the docs' screenshots, serveros.com's analytics script, `install.sh`, `robots.txt` and `.well-known/security.txt`) is copied into the panel's `public/` at the same paths when Vite builds or the dev server starts, with a `.gitignore` in each folder they land in, so they stay out of the panel's git status. A file the copy would overwrite must not be one the panel tracks. The panel answers `/install.sh` and `/robots.txt` from routes, and a web server serves a file in `public/` before it asks the panel, so a package's copy of either wins.
- **Its icons:** `node scripts/solar-icons.mjs` also writes the site's `lib/solarIcons.tsx` and `lib/docIcons.tsx` into the package that has `resources/js/marketing/lib`, and the build splits the site's icon imports one module per icon, as it does the panel's.

A package's write routes in a workspace, account settings or `/admin` say whether they're audited (`#[Audited]` or `#[NotAudited('why')]`) like the panel's: the audit coverage test checks `ServerOS\` controllers too.

## Sidebar sections and settings pages

```php
use App\Support\Extensions\NavItem;
use App\Support\Extensions\SettingsItem;

$extensions->addNavItem(new NavItem(
    key: 'ledger', label: 'Ledger', icon: 'credit-card', path: '/ledger',
    group: 'workspace', components: ['workspace/Ledger'], after: 'members',
    visible: fn (Workspace $workspace, ?User $user) => $user?->can('manageBilling', $workspace) === true,
));

$extensions->addSettingsItem(new SettingsItem(
    key: 'sso', label: 'Single sign-on', icon: 'lock', path: '/settings/sso',
    group: 'workspace', component: 'workspace/settings/Sso', keywords: 'saml oidc okta entra',
    visible: fn (Workspace $workspace) => $workspace->hasFeature(Feature::Sso),
));
```

`after` names the item it goes after; `before` names one it goes before, which wins when that item shows (Billing uses `after: 'members', before: 'settings'`, so it sits after Partners when that shows too).

Only the items whose `visible` check passes are shared with the page, as the `extensions` prop on workspace pages. Labels are in English and translated when shared. Icons are names from `resources/js/lib/extensionIcons.ts`; add one there if you need it. Sidebar groups are `start`, `servers`, `operations`, `workspace` and `menu`. Settings groups are `workspace`, `machines`, `developers` and `account`.

## The admin's sidebar

`resources/js/layouts/AdminLayout.vue` shows the admin pages the panel serves itself (`App\Support\Admin\AdminPages`: Laravel Pulse) and the packages':

```php
use App\Support\Extensions\AdminNavItem;

$extensions->addAdminNavItem(new AdminNavItem(
    key: 'tickets', label: 'Tickets', icon: 'hammer', path: '/admin/tickets',
    group: 'home', components: ['admin/tickets/*'], badge: 'ticketsUnread',
));
```

Groups are `home`, `customers`, `growth`, `content`, `platform` and `tools` (the row of icons at the bottom, outside services that open in a new tab), in that order; one with nothing in it isn't shown, so an install with no admin pages has no sidebar to show. `components` are the pages it's the current one on, and one ending in `*` matches every page that starts with the rest. `after` and `before` place it next to another item, whichever was added first; without them it goes in the order added. `visible` gets the user; without it, anyone who can `viewAdmin` sees it (an item can use another gate, so people who may only see one page get that and nothing else). `badge` names a page prop with a count, which the package shares itself (below), so a partial reload of it updates the badge. `external: true` is a full page load, like Laravel Pulse. Icons are names from `resources/js/lib/adminIcons.ts`. Admin pages get the sidebar as the `adminNav` prop.

The layout has two `ExtensionSlot`s, `layouts/AdminLayout/main-end` (at the end of the page) and `layouts/AdminLayout/after-main` (beside it, for something fixed to the window), where a package can put something like an assistant.

## Props on every page

```php
$extensions->addSharedProps(fn (Request $request) => [
    'ticketsUnread' => fn () => $request->routeIs('admin.*') ? Tickets::unreadFor($request->user()) : 0,
]);
```

They come after the panel's own shared props, so they never move or replace one. Give a closure as a value to work it out only when it's sent.

## What a token can be for: OAuth audiences

The panel's MCP OAuth server issues tokens for a workspace's MCP server (`App\Support\Mcp\WorkspaceAudience`). A package with its own MCP server adds an audience, a subclass of `App\Support\Mcp\OAuthAudience`:

```php
$extensions->addOAuthAudience(new TicketsAudience);
$extensions->keepOnMainDomain(['tickets.mcp']);
```

It says its `key()` (stored as `audience` with its codes and tokens, so never change it), its `scopes()` (listed in the server metadata's `scopes_supported`, after the workspace's), `describe()` and `normalise()` for them, which authorization requests it `claims()` by their `resource`, its `consent()` page (what to keep until the person decides, and the page's props) and what `approve()` grants (`OAuthGrant`: the scopes, the resource and a workspace, if any). Either can return a response instead, like `OAuthPage::problem()`. The consent page (`auth/OAuthAuthorize`) knows a package's audience by a `{key}: true` prop and shows the copy in the package's `resources/js/oauth/{key}.ts` (`OAuthAudienceCopy`). A code or token for an audience nothing installed knows can't be exchanged or refreshed. Its protected resource metadata is the package's own route, in the `MCP` slot. `keepOnMainDomain` names routes whose URLs stay on our domain on a workspace's own domain, as the MCP and API routes do.

## The admin audit log

`App\Models\AdminAction` records what admins do, and what it was about by a short name (`target_type`). A package's models need theirs:

```php
$extensions->addAuditTarget('ticket', Ticket::class, fn (Ticket $ticket) => $ticket->title);
```

`AdminAction::targets()` has the panel's (`AdminAction::TARGETS`) and the packages'. Never change a name once there are rows.

## Account events

`App\Models\AccountEvent` keeps what happened to an account, shown on Settings › Security and the admin's user page. A package that records its own event says how it reads, in English and said to the person; it's translated when shown:

```php
AccountEvent::record($user, 'ledger.profile', ['changed' => ['name']]);
$extensions->addAccountEvent('ledger.profile', 'Changed your ledger profile');
```

An event no package describes shows its name.

## Events

The panel dispatches Laravel events where a package needs to act on something core does. Listen with `Event::listen` from the provider:

| Event                          | When                                                                                                                                                                                                                         |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Events\DeployQueued`      | A deploy was handed to a machine's daemon, with the domains it asked for. Listeners run before the deploy call returns.                                                                                                      |
| `App\Events\WorkspaceCreated`  | A workspace was made for its owner (signing up, from the switcher, or by an admin). Listeners run before `CreateWorkspace` returns it (serveros.com's survey reward).                                                        |
| `App\Events\EmailUnsubscribed` | Someone asked to stop getting email at an address, with the link in an email (`Unsubscribes::add`), the address normalised. Listeners run before the page says it's done (serveros.com marks the address's waitlist signup). |

## The upgrade link

The dashboard's "upgrade", "choose a plan" and plan limit prompts (the workspace menu, the command palette, the plan banner, Connect machine at the limit, plan locks) all go to one place, which a package supplies:

```php
use App\Support\Extensions\UpgradeLink;

$extensions->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.billing.show'));
```

Workspace pages get it as the `upgrade` prop, `{href: '/billing', label: 'Billing'}` (Vue: `useUpgradeLink()`), and PHP asks `$extensions->upgradeUrl($workspace)` for the full address. With none set, `upgrade` is null and those prompts show no link.

## The Powered by link

A workspace's public status pages say "Powered by ServerOS" and its workflow emails "Sent with ServerOS", linking to the install's own address. A package can send that link somewhere else, per workspace (serveros.com's goes through the owner's partner link):

```php
$extensions->setPoweredByLink(fn (Workspace $workspace) => Referrals::linkFor($workspace->owner));
```

`$extensions->poweredByUrl($workspace)` gives the package's address, or `config('app.url')` with none set.

## Off limits while viewing as someone

An admin viewing as someone can look but not act as them in some places. A package names its routes and what the admin is told:

```php
$extensions->offLimitsWhileViewingAs(['workspace.billing.checkout'], 'You’re viewing as this person, so billing is off limits.');
```

## A card before the trial

Whether a new signup's trial has to add a card before its dashboard opens is asked of `App\Support\Extensions\TrialCards`, by workspace creation and the welcome flow's plan step. The panel binds `NoTrialCards` (never); a package with a checkout can bind its own:

```php
$this->app->singleton(TrialCards::class, CheckoutTrialCards::class);
```

While the welcome flow confirms a trial's card, back from checkout, it says what `resources/js/lib/trialCards.ts` gives it: the default words, or a package's own `resources/js/trialCards.ts` (its default export, a `TrialCardsCopy`), which can name its checkout. The strings are in English and go through `$t`.

## How a plan is paid for

The panel keeps a workspace's plan, trial and allowances, but never talks to a payment provider. What it needs to know about a subscription it asks `App\Support\Extensions\Billing`:

- `isBilled($workspace)`: whether a subscription pays for the plan right now (active, or a payment that's late), so a card trial waiting on its first charge keeps its plan.
- `currency($workspace)`: the currency the subscription is charged in, so prices show in it (`Currency::forWorkspace`); null goes by the visitor.
- `summary($workspace)`: what the workspace's pages are told (`Workspace::billingSummary`): the subscription's status, whether there's a portal to manage it in, and the contract it's on, if any.

The panel binds `NoBilling` (nothing is billed); a package with a checkout binds its own from `register()`:

```php
$this->app->singleton(Billing::class, LedgerBilling::class);
```

## Parts of panel pages

A package can add to a page the panel renders, without the panel knowing what it adds:

```php
// Props on the page, merged in by its controller (it calls pagePropsFor).
// The workspace is null on pages outside one, like auth/Login.
$extensions->addPageProps('workspace/settings/Members', fn (Request $request, ?Workspace $workspace) => [
    'scim' => $request->user()?->can('manageMembers', $workspace) ? ScimSettings::for($workspace) : null,
]);

// Fields on each member of the members list, by user id.
$extensions->addMemberProps(fn (Workspace $workspace, array $userIds) => [/* id => ['scim' => true] */]);
```

The page shows them through an `<ExtensionSlot name="..." :parts="slotParts">` (`resources/js/components/ExtensionSlot.vue`): every package component in `resources/js/slots/{name}/*.vue` renders there, in file name order, with the slot's attributes as props. Read the page's props, including the ones you added, with `usePage()`. The page finds the parts itself, with an eager `import.meta.glob` of `vendor/serveroshq/*/resources/js/slots/{page}/**/*.vue`, so they're in the page's own chunk and render with it. Slots so far:

| Page                          | Slot                                               | Gets                                                                |
| ----------------------------- | -------------------------------------------------- | ------------------------------------------------------------------- |
| `workspace/settings/Members`  | `workspace/settings/Members/after-invitations`     | `workspaceName`, `roleOptions` (the roles people can be given here) |
| `workspace/settings/Security` | `workspace/settings/Security/after-google-sign-in` | `workspaceName`                                                     |
| `auth/Login`                  | `auth/Login/notices`                               | nothing                                                             |
| `auth/Login`                  | `auth/Login/sign-in-options`                       | `lastUsed`                                                          |

Add a slot to a page, and a `pagePropsFor` call to its controller, when a package needs one. Give the page `defineOptions({ inheritAttrs: false })`, so props it doesn't declare don't land on its root element.

## Middleware

```php
$extensions->addMiddleware(Extensions::WORKSPACE_SIGN_IN, RequireWorkspaceSso::class);
$extensions->addMiddleware(Extensions::WORKSPACE, RequireCardForTrial::class);
```

Middleware goes into a slot that keeps its place in a list, in the order packages add it. The workspace group has two, so a sign-in check runs with the panel's and a check on the workspace itself runs once the person is fully signed in. Either way it runs on every route in the group, a package's own and the panel's:

| Slot                            | Runs                                                                                                                              |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `Extensions::WORKSPACE_SIGN_IN` | In the workspace group's sign-in checks, after the Google sign-in check and before two-factor (panel-ee's SSO requirement).       |
| `Extensions::WORKSPACE`         | In the workspace group's middleware, after two-factor and before the visit is recorded (serveros.com's card before the trial).    |
| `Extensions::WEB`               | On every request with the `web` group, after `AccountGuard` (through `RunPackageWebMiddleware`, since that group is set earlier). |
| `Extensions::WEB_END`           | On every request with the `web` group, last in it (serveros.com's referral cookies and coming soon).                              |
| `Extensions::PASSWORD_SIGN_IN`  | In Fortify's password sign-in steps, before the code prompt. Steps take `handle($request, $next)` like middleware.                |

So the workspace group runs: signed in, verified, can view the workspace, Google sign-in, `WORKSPACE_SIGN_IN`, two-factor, `WORKSPACE`, the visit recorded, the workspace's own domain.

## Signing in and out

Extend `App\Support\Extensions\SignInHooks` and override what you need, then `$extensions->addSignInHooks(new MySignInHooks)`. The panel asks every one: `signInRoutes()` (a package's sign-in routes, for the account's history), `refuseSocialSignIn()` (turn down Google or GitHub), `signedIn()`, `challenged()` (at the code prompt), `signingOut()` (where to send the browser after), `meetsTwoFactor()` (a session that meets a workspace's two-factor requirement your way) and `handoff()` / `receiveHandoff()` (what a session carries to a workspace's own domain). panel-ee's single sign-on uses all of them.

## Signing up

Extend `App\Support\Extensions\SignUpHooks`, then `$extensions->addSignUpHooks(new MySignUpHooks)`. When someone signs up, with the form or with Google or GitHub, the panel calls every one's `signedUp()` with the new account, in the sign-up's transaction and before its first workspace is made, and asks `source()` for a few words on where it came from, for the team's "New signup" push (the first answer wins). serveros.com saves where a signup came from this way.

## Errors on a package's own endpoints

```php
$extensions->addExceptionRenderer(fn (Throwable $e, Request $request) => $request->is('scim/*') ? Scim::error(...) : null);
```

Asked first, before the panel's API errors and error pages. Return null for requests that aren't yours.

## The install ping

A self-hosted install sends serveros.com an anonymous ping once a day (`App\Support\InstallPing`, described for installs in `docs/self-hosting.md`). A package can add a number or a yes/no to it, and nothing else:

```php
$extensions->addInstallStat('licensed', fn () => Licenses::anyValid());
```

Only a whole number (0 or more) or a boolean is sent; anything else, or a check that throws, is left out. Names are snake_case and can't be the ping's own fields, apart from `licensed`, which is `false` until panel-ee answers it. Whatever a package adds shows in `php artisan install:ping --show`, and serveros.com ignores fields it doesn't know.

## A package's columns on the panel's tables

A package's migrations can add columns to the panel's tables (`loadMigrationsFrom`). When one needs a cast, add it to the model's:

```php
$extensions->addModelCasts(Workspace::class, ['ledger_synced_at' => 'datetime']);
```

The model's `casts()` puts them before its own, so the panel's always win. `Workspace` asks for them; add the call to another model's `casts()` when a package needs it there.

## Everything else

Use Laravel's own package tools: `loadMigrationsFrom`, `mergeConfigFrom`, `commands`, and `callAfterResolving(Schedule::class, ...)` for scheduled jobs.

A package's own strings go in its `lang/{code}.json`, loaded with `loadJsonTranslationsFrom` for `__()`. The build merges them into the panel's `lang/{code}.json` wherever the front end imports one (`packageTranslations()` in `vite.config.ts`), so `$t` has the same lines; the panel's line wins when both have a key. Where the panel's config is neutral (`mail.support.address`, `services.seline.token`, `daemon.install_script`, who runs the install in `serveros.brand.*`), a package can fill in its own from `register()` when the environment left it empty; serveros.com does (`SaasServiceProvider::fillConfig`).

The front-end build reads a package's `build.env.json` (`{"NAME": "value"}`) for settings neither the environment nor `.env` gives, like serveros.com's Sentry project (`SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_URL`) and PostHog proxy (`VITE_POSTHOG_HOST`). Config keys the panel used to have keep their names: merge a package's `config/cloud.php` as `cloud`, or a file of extra services as `services`. Page props every page gets go through `Inertia::share()` in the provider's `boot()`; the panel's own shared props are merged over them, so don't reuse a name the panel shares.

## Installing a package

Require it in the panel's `composer.json` like any other (from its Git repository, as a `vcs` repository, if it isn't on Packagist), and `composer.lock` pins the commit. A package in a private repository needs Composer to have a token that can read it: `composer config --global github-oauth.github.com <token>` on your machine, or a `COMPOSER_AUTH` JSON in CI and in Docker builds (see `docs/self-hosting.md`).

To ship a package change, push it to the package, then run `composer update serveroshq/your-package` in the panel and commit `composer.lock`. Nothing changes where the panel runs until the lock does.

## Developing a package

Clone it next to the panel, then swap the installed copy for a link to your clone, so edits show straight away:

```sh
rm -rf vendor/serveroshq/panel-ee
ln -s ../../../serveros-panel-ee vendor/serveroshq/panel-ee
composer dump-autoload
```

Vite keeps a linked package's path (`preserveSymlinks`), so its pages import from the panel. `composer install` puts the locked copy back.
