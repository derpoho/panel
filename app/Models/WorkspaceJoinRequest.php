<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who signed in with a Google account on one of a workspace's
 * verified domains and asked to join it. An admin lets them in or turns
 * them down. A denied row also stands for someone who was removed or
 * left, so a domain sign-in doesn't bring them straight back; an invite
 * still can.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $domain
 * @property string $state pending, approved or denied
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WorkspaceJoinRequest extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string DENIED = 'denied';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('state', self::PENDING);
    }

    public function decide(string $state, ?User $by): void
    {
        $this->forceFill(['state' => $state, 'decided_by' => $by?->id, 'decided_at' => now()])->save();
    }

    /**
     * Keep someone who was removed (or left) from coming straight back in
     * through their company's domain. Their pending request, if any, is
     * turned down too.
     */
    public static function blockReturn(Workspace $workspace, User $user, ?User $by): void
    {
        $workspace->joinRequests()->where('user_id', $user->id)->pending()->get()->each->decide(self::DENIED, $by);

        if (! $workspace->joinRequests()->where('user_id', $user->id)->where('state', self::DENIED)->exists()) {
            $workspace->joinRequests()->create([
                'user_id' => $user->id,
                'domain' => strtolower((string) substr(strrchr($user->email, '@') ?: '', 1)) ?: null,
                'state' => self::DENIED,
                'decided_by' => $by?->id,
                'decided_at' => now(),
            ]);
        }
    }
}
