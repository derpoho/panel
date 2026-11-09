<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia for the installer's pages: the panel's root view, with only what
 * they need shared, since there may be no database yet for the rest
 * (HandleInertiaRequests).
 */
class HandleInstallerRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => ['user' => null],
        ];
    }
}
