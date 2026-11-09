<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceDomain;
use App\Support\Domains\CustomDomains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request to a workspace's own domain, served as if it had come to the
 * workspace's subdomain of ours, so every route matches as it always has.
 * A status page's own hostname, or its path on the dashboard's domain,
 * goes to that status page. RestoreCustomDomain puts the real host back
 * once the route is found, so sessions, redirects and links all use it.
 */
class ResolveCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $target = CustomDomains::resolve($host);
        if ($target === null) {
            return $next($request);
        }

        $request->attributes->set('custom_domain', $host);
        // The address as visited, put back once the route is found.
        $request->attributes->set('custom_uri', (string) $request->server->get('REQUEST_URI', '/'));
        // Sign-ins on the customer's domain belong to that domain alone.
        config(['session.domain' => null]);

        $path = $request->getPathInfo();
        $statusPath = '/status/'.$target['page'];
        $onStatus = $target['purpose'] === WorkspaceDomain::STATUS
            || ($target['path'] !== null && ($path === '/'.$target['path'] || str_starts_with($path, '/'.$target['path'].'/')));

        if ($target['page'] !== null && $onStatus && $path !== $statusPath && ! str_starts_with($path, $statusPath.'/')) {
            $rest = $target['purpose'] === WorkspaceDomain::STATUS ? $path : substr($path, strlen('/'.$target['path']));
            // Where the page lives on this host, for the links it makes.
            $request->attributes->set('status_base', $target['purpose'] === WorkspaceDomain::STATUS ? '' : '/'.$target['path']);
            $request->attributes->set('status_page', $target['page']);
            self::rewrite($request, (string) config('app.domain'), $statusPath.(in_array($rest, ['', '/'], true) ? '' : $rest));
        } elseif ($target['purpose'] === WorkspaceDomain::STATUS || str_starts_with($path, '/status/')) {
            self::rewrite($request, (string) config('app.domain'));
        } else {
            self::rewrite($request, $target['slug'].'.'.config('app.domain'));
        }

        return $next($request);
    }

    /**
     * Point the request at another host (and path, or whole request URI),
     * as if it had come there.
     */
    public static function rewrite(Request $request, string $host, ?string $path = null, ?string $uri = null): void
    {
        $server = $request->server->all();
        $server['HTTP_HOST'] = $host;
        $server['SERVER_NAME'] = $host;
        if ($uri !== null) {
            $server['REQUEST_URI'] = $uri;
        } elseif ($path !== null) {
            $query = $request->server->get('QUERY_STRING');
            $server['REQUEST_URI'] = $path.(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent(),
        );
    }
}
