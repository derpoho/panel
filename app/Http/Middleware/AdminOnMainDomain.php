<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin lives on the main domain. The routes aren't tied to a host, so
 * a workspace's subdomain/admin would work too and look like part of the
 * workspace; send it to the main domain instead, path and query kept.
 * Except admin/return: leaving "View as" is posted from the customer's
 * workspace, and a redirect would turn that POST into a GET.
 */
class AdminOnMainDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $main = (string) config('app.domain');

        if (($request->is('admin') || $request->is('admin/*')) && ! $request->is('admin/return') && $main !== '' && $request->getHost() !== $main) {
            return redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri());
        }

        return $next($request);
    }
}
