<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt, where the web server doesn't serve a public/robots.txt of its
 * own (a package can bring one). A panel on its own is a private tool:
 * everything is behind a sign-in but
 * status pages, which are shared by their links. So search engines are
 * asked to stay out, which also keeps the panel's address out of results.
 */
class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        return response("# A ServerOS panel: everything here is behind a sign-in, and status\n# pages are shared by their links, so there's nothing to index.\nUser-agent: *\nDisallow: /\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
