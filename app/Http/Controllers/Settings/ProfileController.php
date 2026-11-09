<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AccountEvent;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    #[Audited]
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user->wasChanged('name')) {
            AccountEvent::record($user, 'name.changed', ['name' => $user->name]);
        }
        if ($user->wasChanged('email')) {
            AccountEvent::record($user, 'email.changed', ['email' => $user->email, 'from' => $user->getPrevious()['email'] ?? null]);
        }
        if (! $user->wasChanged(['name', 'email'])) {
            AuditContext::nothingChanged();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    #[Audited]
    public function destroy(ProfileDeleteRequest $request): SymfonyResponse
    {
        $user = $request->user();
        // Outlives the account, email included.
        AccountEvent::record($user, 'account.deleted');

        Auth::logout();

        // Workspaces only they were in go with them (the database cascades);
        // the request has already refused any someone else is in.
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your account is deleted.']);

        return Inertia::location(rtrim((string) config('app.url'), '/').'/');
    }
}
