<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class Edition
{
    public static function selfHosted(): bool
    {
        return config('serveros.edition') === 'self-hosted';
    }

    // Every sign-up owns a workspace, so owning one isn't enough: it's the person who
    // installed the panel (its first account) or someone made an admin with admin:grant.
    public static function installAdmin(?User $user, Workspace $workspace): bool
    {
        return self::selfHosted() && $user !== null
            && $workspace->owner_id === $user->id && $user->can('manageSettings', $workspace)
            && ($user->is_admin || $user->id === User::query()->min('id'));
    }

    public static function withoutBilling(): bool
    {
        return self::selfHosted() && app(Extensions::class)->upgradeLink() === null;
    }

    public static function home(Request $request): RedirectResponse
    {
        return match (true) {
            $request->user() !== null => redirect()->route('dashboard'),
            User::query()->doesntExist() => redirect()->route('register'),
            default => redirect()->route('login'),
        };
    }
}
