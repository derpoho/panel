<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AccountEvent;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AvatarController extends Controller
{
    #[Audited]
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        $user->forceFill([
            'avatar_path' => $request->file('avatar')->store('avatars', 'public'),
        ])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }
        AccountEvent::record($user, 'avatar.changed');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profile picture updated.']);

        return back();
    }

    #[Audited]
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
            AccountEvent::record($user, 'avatar.removed');
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }
}
