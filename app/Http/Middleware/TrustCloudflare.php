<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind Cloudflare, every request arrives from one of its edge
 * addresses. When it really did come through Cloudflare, use
 * the visitor's address Cloudflare passes in CF-Connecting-IP: for the IP
 * a machine enrols from, rate limits, and logs. Its CF-IPCountry is kept
 * too, for the country next to an IP in admin. From anywhere else both
 * headers are ignored, so they can't be spoofed.
 */
class TrustCloudflare
{
    /** Cloudflare's published ranges (cloudflare.com/ips). */
    public const array RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** The request attribute holding the visitor's country, when known. */
    public const string COUNTRY = 'cloudflare.country';

    public function handle(Request $request, Closure $next): Response
    {
        $edge = (string) $request->server->get('REMOTE_ADDR');
        $visitor = trim((string) $request->headers->get('CF-Connecting-IP'));

        if ($visitor !== '' && filter_var($visitor, FILTER_VALIDATE_IP) && IpUtils::checkIp($edge, self::RANGES)) {
            $request->server->set('REMOTE_ADDR', $visitor);
            // Symfony works the client IP out from X-Forwarded-For when the
            // edge is trusted; it isn't, so this is what ip() returns.
            $request->headers->remove('X-Forwarded-For');

            // A country code, or T1 for Tor. XX is Cloudflare not knowing.
            $country = strtoupper(trim((string) $request->headers->get('CF-IPCountry')));
            if (preg_match('/^[A-Z][A-Z0-9]$/', $country) === 1 && $country !== 'XX') {
                $request->attributes->set(self::COUNTRY, $country);
            }
        }

        return $next($request);
    }
}
