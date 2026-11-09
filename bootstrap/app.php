<?php

use App\Http\Middleware\AccountGuard;
use App\Http\Middleware\AdminOnMainDomain;
use App\Http\Middleware\AllowWorkspacePasskeys;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordIfSet;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\RestoreCustomDomain;
use App\Http\Middleware\RunPackageWebMiddleware;
use App\Http\Middleware\SendToInstaller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\Middleware\TrackViewAs;
use App\Http\Middleware\TrustCloudflare;
use App\Support\Edition;
use App\Support\ErrorPage;
use App\Support\Extensions\Extensions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Each workspace's MCP server and its OAuth endpoints.
            Route::middleware('api')->group(base_path('routes/mcp.php'));
            // SCIM provisioning (scim/*) comes from a package's own routes file.
            // A self-hosted install's web installer, until it's set up.
            if (Edition::selfHosted()) {
                require base_path('routes/installer.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare first, so everything after sees the visitor's real
        // address; then a workspace's own domain, served as its subdomain of
        // ours. Each prepend goes in front, so they're added in reverse.
        $middleware->prepend(ResolveCustomDomain::class);
        $middleware->prepend(TrustCloudflare::class);
        // Before all of them: a self-hosted install that isn't set up yet
        // answers only its installer.
        $middleware->prepend(SendToInstaller::class);

        // The installer's password fields are kept exactly as typed.
        $middleware->trimStrings(except: ['db_password', 'mail_password']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'cookie_consent', 'locale', 'ask_chat']);

        // Fortify's two-factor and passkey settings ask for the password
        // through this alias; Google/GitHub sign-ups without one skip it.
        $middleware->alias(['password.confirm' => RequirePasswordIfSet::class]);

        // throttle:N,M counts each route on its own, not one count per person
        // across every throttled route (ThrottlePerRoute says why).
        $middleware->alias(['throttle' => ThrottlePerRoute::class]);

        // Workspace subdomains share the root domain's login page.
        // A workspace's own domain signs in on that domain.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->attributes->has('custom_domain')
            ? url('/login')
            : rtrim((string) config('app.url'), '/').'/login');

        $middleware->web(prepend: [RestoreCustomDomain::class, AdminOnMainDomain::class]);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AccountGuard::class,
            // The packages' (Extensions::WEB).
            RunPackageWebMiddleware::class,
            TrackViewAs::class,
            AllowWorkspacePasskeys::class,
            // The packages' (Extensions::WEB_END), last.
            RunPackageWebMiddleware::class.':'.Extensions::WEB_END,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errors go to Sentry when SENTRY_LARAVEL_DSN is set; nothing otherwise.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'scim/*') || $request->expectsJson(),
        );

        // Errors the packages answer in their own shape (SCIM errors, for
        // one) come first, ahead of the API's and our pages.
        $exceptions->render(fn (Throwable $e, Request $request) => app(Extensions::class)->renderException($e, $request));

        // The REST API answers in its own error shape, never with a trace.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }
            [$type, $message] = match ($e->getStatusCode()) {
                404 => ['not_found', 'There’s no '.$request->method().' /'.$request->path().' route. GET /api/v1/openapi.json lists them all.'],
                405 => ['method_not_allowed', $request->method().' isn’t allowed on /'.$request->path().'. Allowed: '.($e->getHeaders()['Allow'] ?? '').'.'],
                default => ['error', $e->getMessage() ?: 'That didn’t work.'],
            };

            return response()->json(['error' => ['type' => $type, 'message' => $message]], $e->getStatusCode(), $e->getHeaders());
        });

        // Our own page for 404s and friends, on the site and the dashboard.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => ErrorPage::respond($response, $request));
    })->create();
