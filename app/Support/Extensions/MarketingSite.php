<?php

namespace App\Support\Extensions;

/**
 * A website a package draws with its own Inertia app: the routes that are
 * its pages and the root view they render in.
 * Those pages get that root view and their own asset version, so going
 * from the panel to one of them is a full page load, and only they go to
 * the SSR server. The panel's error page on the main domain and its docs
 * and feedback subdomains uses the root view too. With none, every page
 * is the panel's.
 */
final readonly class MarketingSite
{
    /**
     * @param  string  $rootView  The Blade view its pages render in.
     * @param  list<string>  $routes  Its pages' route names, as routeIs() takes them ("legal.*").
     */
    public function __construct(
        public string $rootView,
        public array $routes,
    ) {}
}
