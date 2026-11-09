<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Which install this is: serveros.com ("cloud", the default) or someone's
 * own ("self-hosted", SERVEROS_EDITION=self-hosted, as the Docker image
 * sets it). Only the places where the app assumes serveros.com ask: the
 * front page, coming soon, Cloud, the trial, billing and the welcome
 * flow's plan step, and the emails only serveros.com sends. Everything a
 * workspace may use is App\Support\Entitlements, not this.
 */
final class Edition
{
    public static function selfHosted(): bool
    {
        return config('serveros.edition') === 'self-hosted';
    }

    /**
     * The front page of a self-hosted install, which has no marketing site:
     * the dashboard once signed in, signing in otherwise, and creating the
     * first account (the install's owner) while there is none.
     */
    public static function home(Request $request): RedirectResponse
    {
        return match (true) {
            $request->user() !== null => redirect()->route('dashboard'),
            User::query()->doesntExist() => redirect()->route('register'),
            default => redirect()->route('login'),
        };
    }
}
