<?php

use App\Enums\Feature;
use App\Enums\Plan;
use App\Events\WorkspaceCreated;
use App\Http\Middleware\AllowWorkspacePasskeys;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordWorkspaceVisit;
use App\Http\Middleware\RequireWorkspaceGoogleSignIn;
use App\Http\Middleware\RequireWorkspaceTwoFactor;
use App\Http\Middleware\RunPackageWebMiddleware;
use App\Jobs\SendNtfy;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\MarketingSite;
use App\Support\Extensions\NavItem;
use App\Support\Extensions\NoTrialCards;
use App\Support\Extensions\SettingsItem;
use App\Support\Extensions\SignUpHooks;
use App\Support\Extensions\TrialCards;
use App\Support\Extensions\UpgradeLink;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/** Middleware a test package adds to the workspace group. */
class ExtensionsTestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Extension-Middleware', 'ran');

        return $response;
    }
}

/**
 * Load the route files again, as at boot, so routes a test registered with
 * Extensions land in their slots.
 */
function reloadRoutesWithExtensions(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));

    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
    app('url')->setRoutes($router->getRoutes());
}

function workspaceUrlForExtensions(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

test('a package’s workspace routes get the workspace group’s binding and sign-in checks', function () {
    app(Extensions::class)->routes(Extensions::WORKSPACE, function () {
        Route::get('extension-check', fn (Workspace $workspace) => "hello {$workspace->slug}")->name('workspace.extension-check');
    });
    reloadRoutesWithExtensions();

    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/extension-check'))
        ->assertOk()
        ->assertSee("hello {$workspace->slug}");

    // Someone outside the workspace is turned away by the group's middleware.
    $this->actingAs(User::factory()->create())
        ->get(workspaceUrlForExtensions($workspace, '/extension-check'))
        ->assertForbidden();
});

test('a package’s web and API routes load on the main domain and under /api', function () {
    $extensions = app(Extensions::class);
    $extensions->routes(Extensions::WEB, fn () => Route::get('extension-web-check', fn () => 'web ok'));
    $extensions->routes(Extensions::API, fn () => Route::get('extension-api-check', fn () => 'api ok'));
    reloadRoutesWithExtensions();

    $this->get('/extension-web-check')->assertOk()->assertSee('web ok');
    $this->get('/api/extension-api-check')->assertOk()->assertSee('api ok');
});

test('a package’s subdomain sites load ahead of the workspace subdomains, so their names never reach a workspace', function () {
    app(Extensions::class)->routes(Extensions::SUBDOMAINS, function () {
        Route::domain('extension-site.'.config('app.domain'))->get('/', fn () => 'site ok');
    });
    reloadRoutesWithExtensions();

    // Signed out: a workspace route would send this to sign in instead.
    $this->get('https://extension-site.'.config('app.domain').'/')->assertOk()->assertSee('site ok');
});

test('there are only the panel’s route slots', function () {
    app(Extensions::class)->routes('docs', fn () => null);
})->throws(InvalidArgumentException::class);

test('a page preloads from the panel’s own file, or else from the package that has it', function () {
    expect(Extensions::pageEntry('workspace/Home', 'pages', 'vue'))->toBe('resources/js/pages/workspace/Home.vue')
        ->and(Extensions::pageEntry('NoSuchSitePage', 'marketing/pages', 'tsx'))->toBe('resources/js/marketing/pages/NoSuchSitePage.tsx')
        ->and(Extensions::pageEntry('admin/NoSuchPage', 'pages', 'vue'))->toBe('resources/js/pages/admin/NoSuchPage.vue');

    $folder = base_path('vendor/serveroshq/test-package/resources/js/pages/admin');
    @mkdir($folder, 0777, true);
    file_put_contents("{$folder}/PackagePage.vue", '<template><p /></template>');

    try {
        expect(Extensions::pageEntry('admin/PackagePage', 'pages', 'vue'))
            ->toBe('vendor/serveroshq/test-package/resources/js/pages/admin/PackagePage.vue');
    } finally {
        File::deleteDirectory(base_path('vendor/serveroshq/test-package'));
    }
});

test('workspace pages carry the sections and settings pages packages add, for who may see them', function () {
    // Only this test's, without the ones installed packages add.
    app()->instance(Extensions::class, $extensions = new Extensions);
    $extensions->addNavItem(new NavItem(
        key: 'ledger',
        label: 'Ledger',
        icon: 'credit-card',
        path: '/ledger',
        group: 'workspace',
        components: ['workspace/Ledger'],
        after: 'members',
        visible: fn (Workspace $workspace, ?User $user) => $user?->is($workspace->owner) === true,
    ));
    $extensions->addSettingsItem(new SettingsItem(
        key: 'sso-extension',
        label: 'Single sign-on',
        icon: 'lock',
        path: '/settings/sso-extension',
        group: 'workspace',
        component: 'workspace/settings/SsoExtension',
        keywords: 'saml oidc',
        sections: [['label' => 'Connections', 'anchor' => 'connections']],
        visible: fn (Workspace $workspace) => $workspace->hasFeature(Feature::Sso),
    ));

    $pro = Workspace::factory()->onPlan(Plan::Pro)->create();
    $business = Workspace::factory()->onPlan(Plan::Business)->create();
    $member = User::factory()->create();
    $pro->members()->attach($member, ['role' => 'admin']);

    // The owner sees the section; Pro has no SSO, so no settings page.
    $this->actingAs($pro->owner)
        ->get(workspaceUrlForExtensions($pro, '/'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('extensions.nav.0.key', 'ledger')
            ->where('extensions.nav.0.path', '/ledger')
            ->where('extensions.nav.0.after', 'members')
            ->where('extensions.nav.0.components', ['workspace/Ledger'])
            ->where('extensions.settings', []));

    // Another admin doesn't see the owner-only section.
    $this->actingAs($member)
        ->get(workspaceUrlForExtensions($pro, '/'))
        ->assertInertia(fn (Assert $page) => $page->where('extensions.nav', []));

    // Business has SSO, so its settings page shows, with its sections.
    $this->actingAs($business->owner)
        ->get(workspaceUrlForExtensions($business, '/'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('extensions.settings.0.key', 'sso-extension')
            ->where('extensions.settings.0.group', 'workspace')
            ->where('extensions.settings.0.sections.0.anchor', 'connections')
            ->where('extensions.settings.0.sections.0.keywords', ''));
});

test('with nothing registered, workspace pages share empty lists and no upgrade link', function () {
    app()->instance(Extensions::class, new Extensions);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('extensions.nav', [])
            ->where('extensions.settings', [])
            ->where('upgrade', null));

    // Pages that link out to it say there's nowhere to go.
    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/settings/domains'))
        ->assertInertia(fn (Assert $page) => $page->where('billingUrl', null));
});

test('a package’s routes can be off limits while viewing as someone, with its own message', function () {
    $extensions = new Extensions;
    $extensions->offLimitsWhileViewingAs(['workspace.ledger.pay'], 'No paying as them.');

    expect($extensions->offLimitsMessage('workspace.ledger.pay'))->toBe('No paying as them.')
        ->and($extensions->offLimitsMessage('workspace.home'))->toBeNull()
        ->and($extensions->offLimitsMessage(null))->toBeNull();
});

test('a package’s middleware runs on every workspace route, after the sign-in checks', function () {
    app(Extensions::class)->addMiddleware(Extensions::WORKSPACE, ExtensionsTestMiddleware::class);
    reloadRoutesWithExtensions();

    $middleware = Route::getRoutes()->getByName('workspace.home')->middleware();
    $at = fn (string $class) => array_search($class, $middleware, true);
    expect($at(ExtensionsTestMiddleware::class))->toBeInt()
        ->toBeGreaterThan($at(RequireWorkspaceTwoFactor::class))
        ->toBeLessThan($at(RecordWorkspaceVisit::class));

    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/'))
        ->assertOk()
        ->assertHeader('X-Extension-Middleware', 'ran');
});

test('a package’s sign-in middleware runs on every workspace route, between the Google sign-in check and two-factor', function () {
    app(Extensions::class)->addMiddleware(Extensions::WORKSPACE_SIGN_IN, ExtensionsTestMiddleware::class);
    reloadRoutesWithExtensions();

    $middleware = Route::getRoutes()->getByName('workspace.home')->middleware();
    $at = fn (string $class) => array_search($class, $middleware, true);
    expect($at(ExtensionsTestMiddleware::class))->toBeInt()
        ->toBeGreaterThan($at(RequireWorkspaceGoogleSignIn::class))
        ->toBeLessThan($at(RequireWorkspaceTwoFactor::class));

    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/'))
        ->assertOk()
        ->assertHeader('X-Extension-Middleware', 'ran');
});

test('there are only five middleware slots', function () {
    app(Extensions::class)->addMiddleware('docs', ExtensionsTestMiddleware::class);
})->throws(InvalidArgumentException::class);

test('with no package asking for a card, the welcome flow never does', function () {
    app()->instance(TrialCards::class, new NoTrialCards);
    $workspace = Workspace::factory()->welcoming()->create(['signup_variant' => 'card']);

    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome/Welcome')
            ->where('cardRequired', false)
            ->where('cardTrialDays', 0)
            ->where('cardVerifyUrl', null));

    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrlForExtensions($workspace, '/welcome/card'), ['plan' => 'pro', 'interval' => 'monthly'])
        ->assertForbidden();
});

test('what packages add shows on workspace pages whose routes have no name, like the tab pages', function () {
    $extensions = app(Extensions::class);
    $extensions->addNavItem(new NavItem(key: 'probe', label: 'Probe', icon: 'settings', path: '/probe', group: 'workspace'));
    $extensions->setUpgradeLink(new UpgradeLink(label: 'Plans', route: 'workspace.home'));

    $workspace = Workspace::factory()->create();

    // backups/{tab} is in the workspace group but has no route name.
    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/backups/history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upgrade.label', 'Plans')
            ->where('upgrade.href', '/')
            ->where('extensions.nav', fn ($nav) => collect($nav)->contains('key', 'probe')));
});

test('pages outside the workspace group get no upgrade link or package sections, even with a workspace in the address', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Plans', route: 'workspace.home'));
    // A page about a workspace on the main domain, like the staff admin's.
    Route::middleware(['web', 'auth'])->get('test/workspaces/{workspace}', fn (Workspace $workspace) => Inertia::render('settings/Profile'));

    $this->actingAs($admin)
        ->get('/test/workspaces/'.$workspace->id)
        ->assertInertia(fn (Assert $page) => $page->where('upgrade', null)->where('extensions', null));
});

test('a package can add props to a panel page and fields to each member', function () {
    $extensions = app(Extensions::class);
    $extensions->addPageProps('workspace/settings/Members', fn (Request $request, ?Workspace $workspace) => [
        'ledgerNote' => "{$workspace?->slug} for {$request->user()?->name}",
    ]);
    $extensions->addMemberProps(fn (Workspace $workspace, array $userIds) => array_fill_keys($userIds, ['ledger' => true]));

    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(workspaceUrlForExtensions($workspace, '/settings/members'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/Members')
            ->where('ledgerNote', "{$workspace->slug} for {$workspace->owner->name}")
            ->where('members.0.ledger', true));

    // Other pages don't get them.
    expect($extensions->pagePropsFor('workspace/settings/General', request(), $workspace))->toBe([]);
});

test('a package answers errors on its own endpoints first, and leaves the rest alone', function () {
    app(Extensions::class)->addExceptionRenderer(fn (Throwable $e, Request $request) => $request->is('ledger-api/*')
        ? response()->json(['ledger' => 'gone'], 418)
        : null);

    $this->getJson('/ledger-api/nothing-here')->assertStatus(418)->assertJson(['ledger' => 'gone']);
    // Anything else still gets the panel's own answer.
    $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('error.type', 'not_found');
});

test('a package’s web middleware at the end runs last in the web group, on every web request', function () {
    $web = app('router')->getMiddlewareGroups()['web'];
    expect(end($web))->toBe(RunPackageWebMiddleware::class.':'.Extensions::WEB_END)
        ->and(array_search(AllowWorkspacePasskeys::class, $web, true))->toBe(count($web) - 2);

    app(Extensions::class)->addMiddleware(Extensions::WEB_END, ExtensionsTestMiddleware::class);

    $this->get('/login')->assertOk()->assertHeader('X-Extension-Middleware', 'ran');
});

test('signing up tells the packages about the new account before its workspace, and asks where it came from', function () {
    config()->set('services.ntfy.topic', 'team');
    Queue::fake([SendNtfy::class]);
    Event::fake([WorkspaceCreated::class]);
    $hooks = new class extends SignUpHooks
    {
        /** @var list<array{string, int}> */
        public array $seen = [];

        public function signedUp(Request $request, User $user): void
        {
            $this->seen[] = [$user->email, $user->ownedWorkspaces()->count()];
        }

        public function source(User $user): ?string
        {
            return 'a test';
        }
    };
    app(Extensions::class)->addSignUpHooks($hooks);

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $ada = User::query()->where('email', 'ada@example.com')->firstOrFail();
    expect($hooks->seen)->toBe([['ada@example.com', 0]]);
    Event::assertDispatched(WorkspaceCreated::class, fn (WorkspaceCreated $event) => $event->owner->is($ada) && $event->workspace->owner_id === $ada->id);
    Queue::assertPushed(SendNtfy::class, fn (SendNtfy $push) => $push->title === 'New signup' && str_ends_with($push->message, ' from a test'));
});

test('a package’s website pages get its root view and their own asset version; with none, every page is the panel’s', function () {
    app()->instance(Extensions::class, $extensions = new Extensions);
    $middleware = app(HandleInertiaRequests::class);
    $request = Request::create('https://'.config('app.domain').'/somewhere');
    $route = (new RoutingRoute('GET', 'somewhere', fn () => null))->name('site.page');
    $request->setRouteResolver(fn () => $route);

    expect($middleware->rootView($request))->toBe('app')
        ->and($middleware->shouldServerRender($request))->toBeFalse()
        ->and((string) $middleware->version($request))->not->toStartWith('marketing-');

    $extensions->setMarketingSite(new MarketingSite(rootView: 'site::page', routes: ['site.*']));

    expect($middleware->rootView($request))->toBe('site::page')
        ->and($middleware->shouldServerRender($request))->toBeTrue()
        ->and((string) $middleware->version($request))->toStartWith('marketing-');

    $other = (new RoutingRoute('GET', 'elsewhere', fn () => null))->name('other');
    $request->setRouteResolver(fn () => $other);
    expect($middleware->rootView($request))->toBe('app');
});
