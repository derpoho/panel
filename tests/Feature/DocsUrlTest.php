<?php

use App\Support\Docs\DocsUrl;
use Illuminate\Routing\RouteCollection;

test('docs links go to the docs site when the install has one, and to serveros.com’s docs when it doesn’t', function () {
    $router = app('router');
    $routes = $router->getRoutes();

    if ($router->has('docs.show')) {
        expect(DocsUrl::page('guides', 'api'))->toBe(route('docs.show', ['guides', 'api']));
    }

    $router->setRoutes(new RouteCollection);
    try {
        expect(DocsUrl::page('guides', 'api'))->toBe('https://docs.serveros.com/guides/api');
    } finally {
        $router->setRoutes($routes);
    }
});
