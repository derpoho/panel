<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Our own page for errors people can hit, in place of the framework's
 * plain one: the site's (errors/Error in a package's MarketingSite) on the
 * site, the docs and the feedback board, the dashboard's (errors/Error.vue)
 * elsewhere and whenever no package draws a site. JSON and API requests
 * keep their JSON, and while debugging, server errors still show the stack
 * trace.
 */
class ErrorPage
{
    private const STATUSES = [403, 404, 419, 429, 500, 503];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || $request->is('api/*', 'scim/*')
            || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        // A missing page never reached the middleware that picks the language.
        $locale = SetLocale::for($request);
        App::setLocale($locale);
        Carbon::setLocale($locale);

        try {
            // The site, the docs and the feedback board get the site's own
            // page when a package draws the site; a workspace's address, and
            // an install without one, keep the dashboard's.
            $site = app(Extensions::class)->marketingSite();
            if ($site !== null && self::onSite($request)) {
                Inertia::setRootView($site->rootView);
            }

            return Inertia::render('errors/Error', [
                'status' => $status,
                'home' => self::home($request),
                'site' => rtrim((string) config('app.url'), '/').'/',
                // Where to write when something broke on our side.
                'support' => (string) config('mail.support.address'),
            ])->toResponse($request)->setStatusCode($status);
        } catch (\Throwable) {
            // If our page can't be drawn either, the plain one will do.
            return $response;
        }
    }

    /** The site itself, or its docs or feedback board. */
    private static function onSite(Request $request): bool
    {
        $domain = (string) config('app.domain');

        return in_array($request->getHost(), [$domain, "docs.{$domain}", "feedback.{$domain}"], true);
    }

    /**
     * Where "back" goes: the dashboard on a workspace's address, the docs
     * on the docs site, otherwise the homepage.
     *
     * @return array{href: string, label: string}
     */
    private static function home(Request $request): array
    {
        $site = rtrim((string) config('app.url'), '/').'/';
        $domain = (string) config('app.domain');
        $host = $request->getHost();

        if ($host === "docs.{$domain}") {
            return ['href' => '/', 'label' => 'Back to the docs'];
        }

        if ($host !== $domain && str_ends_with($host, ".{$domain}")) {
            $slug = substr($host, 0, -strlen(".{$domain}"));
            if ($request->user() !== null && Workspace::query()->where('slug', $slug)->exists()) {
                return ['href' => '/', 'label' => 'Back to dashboard'];
            }
        }

        return ['href' => $site, 'label' => 'Go to the homepage'];
    }
}
