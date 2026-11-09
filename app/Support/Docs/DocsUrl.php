<?php

namespace App\Support\Docs;

use Illuminate\Support\Facades\Route;

/**
 * Where a docs page is read. The docs site (docs.{domain}) comes from a
 * package; where it's installed, this is its address, as route('docs.show')
 * gives it. An install without the docs site, like a self-hosted one, links
 * to serveros.com's docs instead, which describe the same panel.
 */
final class DocsUrl
{
    /** serveros.com's docs, for installs that don't serve their own. */
    public const string PUBLIC = 'https://docs.serveros.com';

    /** The docs' front page, without a trailing slash. */
    public static function home(): string
    {
        return Route::has('docs.index') ? rtrim(route('docs.index'), '/') : self::PUBLIC;
    }

    public static function page(string $section, string $page): string
    {
        return Route::has('docs.show')
            ? route('docs.show', [$section, $page])
            : self::PUBLIC.'/'.rawurlencode($section).'/'.rawurlencode($page);
    }
}
