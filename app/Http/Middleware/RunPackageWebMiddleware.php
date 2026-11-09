<?php

namespace App\Http\Middleware;

use App\Support\Extensions\Extensions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web group's places for packages' middleware: WEB, after AccountGuard
 * (say, one that signs out a session its identity provider logged out),
 * and WEB_END, last in the group. The web group is set before the
 * packages load, so it's this one that runs theirs, in the order they added
 * them; the slot comes as its parameter, WEB when there's none.
 */
class RunPackageWebMiddleware
{
    public function __construct(private readonly Extensions $extensions) {}

    public function handle(Request $request, Closure $next, string $slot = Extensions::WEB): Response
    {
        $middleware = $this->extensions->middleware($slot);
        if ($middleware === []) {
            return $next($request);
        }

        return (new Pipeline(app()))->send($request)->through($middleware)->then($next);
    }
}
