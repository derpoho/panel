<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;
use Throwable;

/**
 * Inertia's SSR gateway, remembering what it rendered for guests.
 *
 * Rendering a marketing page in the SSR server took 50 to 150ms of a
 * 90 to 250ms response, while Laravel's part was about 35ms. Every guest
 * gets the same page data for the same page, so the HTML is kept for an
 * hour under a hash of that data. The data carries the build version, so
 * a deploy misses on its own. Signed-in visitors' pages carry their own
 * data and aren't kept. Kept compressed, since Redis here never evicts.
 */
class CachedSsrGateway extends HttpGateway
{
    private const TTL_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page, ?Request $request = null): ?Response
    {
        $request ??= request();
        if (! $this->ssrIsEnabled($request) || $request->user() !== null) {
            return parent::dispatch($page, $request);
        }

        $key = 'ssr:'.sha1((string) json_encode($page));
        try {
            $kept = Cache::get($key);
        } catch (Throwable) {
            $kept = null;
        }
        if (is_string($kept) && ($decoded = @gzinflate($kept)) !== false) {
            $parts = json_decode($decoded, true);
            if (is_array($parts) && is_string($parts['head'] ?? null) && is_string($parts['body'] ?? null)) {
                return new Response($parts['head'], $parts['body']);
            }
        }

        $response = parent::dispatch($page, $request);
        if ($response !== null && $response->body !== '') {
            try {
                Cache::put($key, gzdeflate((string) json_encode(['head' => $response->head, 'body' => $response->body]), 6), self::TTL_SECONDS);
            } catch (Throwable) {
                // A cache that's down only costs the render.
            }
        }

        return $response;
    }
}
