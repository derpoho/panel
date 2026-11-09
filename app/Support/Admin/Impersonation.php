<?php

namespace App\Support\Admin;

use App\Models\AdminAction;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Audit\AuditContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "View as": an admin signed in as someone else to see what they see. The
 * admin's id rides along in the session, so they can get back, and the
 * pages show a bar saying whose account it is. Each time is kept as an
 * ImpersonationSession, with the reason given, until it ends.
 */
class Impersonation
{
    /** The admin's id in the session; a session with it is staff viewing as someone. */
    public const string KEY = 'impersonator_id';

    private const string SESSION = 'impersonation_session_id';

    /** Where the admin is kept for the rest of the request, once looked up. */
    private const string RESOLVED = 'impersonation.admin';

    public static function start(Request $request, User $admin, User $user, string $reason): void
    {
        $session = ImpersonationSession::query()->create([
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'reason' => $reason,
            'ip' => AuditContext::ip(),
            'user_agent' => AuditContext::userAgent(),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
        AdminAction::record($admin, 'user.impersonated', $user, ['reason' => $reason], session: $session);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // A connect the admin started for their own account doesn't carry over.
        $request->session()->forget('oauth.connect');
        $request->session()->put(self::KEY, $admin->id);
        $request->session()->put(self::SESSION, $session->id);
        $request->attributes->remove(self::RESOLVED);
    }

    /**
     * Back to the admin's own account. Returns them, or null if this
     * session wasn't viewing as anyone (or that admin no longer is one).
     */
    public static function stop(Request $request): ?User
    {
        $admin = self::impersonator($request);
        self::end($request, $admin === null ? 'admin_revoked' : 'returned');
        $request->session()->forget([self::KEY, self::SESSION]);
        $request->attributes->remove(self::RESOLVED);
        if ($admin === null) {
            return null;
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return $admin;
    }

    /**
     * Close this browser session's View as record, however it ended:
     * the way back, signing out, or the admin losing their access.
     */
    public static function end(Request $request, string $how): void
    {
        $id = self::sessionId($request);
        if ($id !== null) {
            ImpersonationSession::query()->find($id)?->finish($how);
        }
    }

    public static function active(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY);
    }

    public static function impersonator(Request $request): ?User
    {
        if (! self::active($request)) {
            return null;
        }

        if (! $request->attributes->has(self::RESOLVED)) {
            $admin = User::query()->whereKey($request->session()->get(self::KEY))->first();
            $request->attributes->set(self::RESOLVED, $admin?->is_admin ? $admin : null);
        }

        return $request->attributes->get(self::RESOLVED);
    }

    /** The ImpersonationSession this browser session is in, if any. */
    public static function sessionId(Request $request): ?int
    {
        if (! self::active($request)) {
            return null;
        }
        $id = $request->session()->get(self::SESSION);

        return is_numeric($id) ? (int) $id : null;
    }
}
