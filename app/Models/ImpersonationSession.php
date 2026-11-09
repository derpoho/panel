<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One "View as": a ServerOS admin signed in as a customer, from start to
 * end. What they changed meanwhile points back here, so the customer can
 * be shown "ServerOS Support" while we can still say which admin it was.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property int|null $user_id
 * @property string $admin_name
 * @property string $admin_email
 * @property string $user_name
 * @property string $user_email
 * @property string $reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_how returned, signed_out, expired or admin_revoked
 * @property-read User|null $admin
 * @property-read User|null $user
 */
class ImpersonationSession extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** What may still be filled in once it exists: how and when it ended. */
    private const array CLOSING = ['ended_at', 'ended_how', 'last_seen_at'];

    protected static function booted(): void
    {
        // Part of the audit trail: nothing but its end changes, and only
        // the prune removes one (the rows crediting it would lose Support).
        static::updating(function (self $session): void {
            if (array_diff(array_keys($session->getDirty()), self::CLOSING) !== []) {
                throw new LogicException('View as sessions are never edited.');
            }
        });
        static::deleting(fn () => throw new LogicException('View as sessions are only pruned.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AdminAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AdminAction::class);
    }

    /** @return HasMany<ImpersonationView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(ImpersonationView::class);
    }

    /**
     * Drop View as history past AdminAction::KEEP_DAYS: the pages opened,
     * and sessions that ended that long ago once nothing else points at
     * them. A deploy, job, run, invite or account event still credited to
     * ServerOS Support keeps its session, or it would read as the customer.
     */
    public static function prune(): int
    {
        $cutoff = now()->subDays(AdminAction::KEEP_DAYS);
        $views = ImpersonationView::query()->where('created_at', '<', $cutoff)->delete();

        $sessions = self::query()->where('ended_at', '<', $cutoff);
        foreach (['activities', 'admin_actions', 'account_events', 'daemon_jobs', 'workflow_runs', 'workspace_invitations'] as $table) {
            $sessions->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn("{$table}.impersonation_session_id", 'impersonation_sessions.id'));
        }

        return $views + $sessions->delete();
    }

    /**
     * Close it and note that in the admin's history. Nothing happens if
     * it's already closed. A session that just went quiet ends when it was
     * last used, not when somebody noticed.
     */
    public function finish(string $how): void
    {
        if ($this->ended_at !== null) {
            return;
        }

        $this->forceFill([
            'ended_at' => $how === 'expired' ? $this->last_seen_at : now(),
            'ended_how' => $how,
        ])->save();

        // Either account may be gone by now (deleted while being viewed, or
        // the admin's removed): the end is still noted, naming them as they were.
        AdminAction::recordForSession($this, 'user.impersonation_ended', $this->user, [
            'as' => $this->user_name,
            'how' => $how,
            'minutes' => $this->minutes(),
        ]);
    }

    /** How long it lasted, or has so far, rounded up to the minute. */
    public function minutes(): int
    {
        return (int) ceil($this->started_at->diffInSeconds($this->ended_at ?? now()) / 60);
    }
}
