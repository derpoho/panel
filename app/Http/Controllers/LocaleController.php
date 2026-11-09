<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Change language. Kept on the account when signed in, and in a cookie
 * either way (shared across the workspace subdomains), so the marketing
 * site and the panel agree.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('app.locales')))],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $locale])->save();

        Cookie::queue(Cookie::forever(SetLocale::COOKIE, $locale, domain: config('session.domain')));

        return back();
    }
}
