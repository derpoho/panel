<?php

use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\McpTermsController;
use App\Http\Controllers\Settings\NavLayoutController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Middleware\RequirePasswordIfSet;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/avatar', [AvatarController::class, 'update'])->name('profile.avatar.update');
    Route::delete('settings/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePasswordIfSet::class)
        ->name('security.edit');

    // Google and GitHub accounts to sign in with.
    Route::get('settings/connections/{provider}', [SocialLoginController::class, 'connect'])
        ->whereIn('provider', SocialAccount::PROVIDERS)
        ->middleware('throttle:10,1')
        ->name('social.connect');
    Route::delete('settings/connections/{provider}', [SocialLoginController::class, 'disconnect'])
        ->whereIn('provider', SocialAccount::PROVIDERS)
        ->name('social.disconnect');

    // The browsers signed in to the account.
    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
        ->name('sessions.destroy-others');
    Route::delete('settings/sessions/{key}', [SessionController::class, 'destroy'])
        ->where('key', '[a-f0-9]{32}')
        ->name('sessions.destroy');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::put('settings/appearance/navigation', NavLayoutController::class)
        ->middleware('throttle:30,1')
        ->name('nav-layout.update');

    Route::post('settings/mcp-terms', McpTermsController::class)
        ->middleware('throttle:10,1')
        ->name('mcp-terms.accept');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
