<?php

namespace App\Support\Mcp;

use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth consent page's responses that aren't a consent: a problem,
 * shown instead of redirecting (so a bad request can't bounce people
 * anywhere), and the headers every consent page has.
 */
final class OAuthPage
{
    public static function problem(string $message): Response
    {
        return self::frameless(Inertia::render('auth/OAuthAuthorize', ['problem' => $message])->toResponse(request()));
    }

    /** Consent pages can't be framed, so they can't be clickjacked. */
    public static function frameless(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
