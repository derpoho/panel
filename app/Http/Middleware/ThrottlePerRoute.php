<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's throttle:N,M keys its count by the person (or, signed out, the
 * IP) alone, so every route with a plain throttle shares one count: a page
 * polling a 60-a-minute status route would use up the 10-a-minute allowance
 * of "make an install command" before anyone clicked it. Each route keeps
 * its own count here. Named limiters
 * (throttle:waitlist) are untouched: they make their own keys.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $which = $route === null ? '' : ($route->getName() ?? implode('|', $route->methods()).' '.$route->uri());

        return parent::resolveRequestSignature($request).'|'.sha1($which);
    }
}
