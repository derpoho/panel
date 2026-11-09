<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AccountEvent;
use App\Models\User;
use App\Support\Admin\Impersonation;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\Device;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use stdClass;

/**
 * The browsers signed in to your account, from the session table, and
 * signing one or all the others out: after a lost laptop, say. Each is
 * named by a hash of its session id, never the id itself.
 */
class SessionController extends Controller
{
    /**
     * Where you're signed in, this browser first. Staff viewing as you
     * aren't one of your devices, so their session is left out.
     *
     * @return list<array{key: string, device: string|null, ip: string|null, lastActiveAt: string, current: bool}>
     */
    public static function devices(Request $request): array
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        return array_values(self::sessions($request->user())
            ->reject(fn (stdClass $s) => self::viewingAs($s))
            ->map(fn (stdClass $s) => [
                'key' => self::key($s->id),
                'device' => Device::label($s->user_agent),
                'ip' => $s->ip_address,
                'lastActiveAt' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
                'current' => $s->id === $current,
            ])
            ->sortByDesc(fn (array $d) => [$d['current'], $d['lastActiveAt']])
            ->all());
    }

    /** Sign out one other browser. */
    #[Audited]
    public function destroy(Request $request, string $key): RedirectResponse
    {
        $user = $request->user();
        $session = self::sessions($user)->first(fn (stdClass $s) => self::key($s->id) === $key && $s->id !== $request->session()->getId());
        if ($session === null) {
            AuditContext::nothingChanged();

            return back();
        }

        DB::table(config('session.table', 'sessions'))->where('id', $session->id)->delete();
        $this->forgetRememberedBrowsers($request, $user);
        AccountEvent::record($user, 'sessions.signed_out', ['scope' => 'one', 'device' => Device::label($session->user_agent) ?? $session->ip_address]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out.')]);

        return back();
    }

    /** Sign out every browser but this one. */
    #[Audited]
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user();
        $count = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
        $this->forgetRememberedBrowsers($request, $user);
        AccountEvent::record($user, 'sessions.signed_out', ['scope' => 'others', 'count' => $count]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out everywhere else.')]);

        return back();
    }

    /**
     * A browser that was told to remember you would sign straight back in,
     * so the remember token changes. This browser gets the new one if it
     * had the old.
     */
    private function forgetRememberedBrowsers(Request $request, User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        $guard = Auth::guard('web');
        if ($guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName())) {
            // Not a sign-in: the listener only counts sign-in routes.
            $guard->login($user, remember: true);
        }
    }

    /** @return Collection<int, stdClass> rows with id, ip_address, user_agent, payload and last_activity */
    public static function sessions(User $user): Collection
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'payload', 'last_activity']);
    }

    /**
     * Whether staff are viewing as the person in this session. One whose
     * payload can't be read (encrypted, or not the JSON config/session.php
     * asks for) counts as theirs: better a device missing from the list
     * than the admin's browser on it.
     */
    public static function viewingAs(stdClass $session): bool
    {
        $data = json_decode((string) base64_decode((string) $session->payload, true), true);

        return ! is_array($data) || array_key_exists(Impersonation::KEY, $data);
    }

    public static function key(string $id): string
    {
        return substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 32);
    }
}
