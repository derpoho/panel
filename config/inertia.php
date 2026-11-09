<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        // A package's website pages only: HandleInertiaRequests::shouldServerRender().
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        // Built by `npm run build:ssr`, run by `php artisan inertia:start-ssr`.
        'bundle' => base_path('bootstrap/ssr/ssr.js'),
        // Without the bundle there's no SSR server either: skip the request.
        'ensure_bundle_exists' => true,
        // Seconds to wait before drawing the page in the browser instead. A
        // stopped server refuses at once; this bounds a stuck one.
        'timeout' => (float) env('INERTIA_SSR_TIMEOUT', 1.5),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        // The panel's own, then the packages' (vendor/serveroshq), which
        // name their pages the same way, and their websites'.
        'paths' => [
            resource_path('js/pages'),
            ...glob(base_path('vendor/serveroshq/*/resources/js/pages'), GLOB_ONLYDIR) ?: [],
            ...glob(base_path('vendor/serveroshq/*/resources/js/marketing/pages'), GLOB_ONLYDIR) ?: [],
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
