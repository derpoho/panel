<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Support\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation to join a workspace. Only a hash of the token is stored; the
 * plain token travels in the emailed link. Accepted invitations are deleted.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property int|null $custom_role_id a Business workspace's own role, on top of $role
 * @property string $token
 * @property int|null $invited_by
 * @property int|null $impersonation_session_id staff sent it while viewing as the inviter
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['email', 'role', 'expires_at'])]
#[Hidden(['token'])]
class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    public const int EXPIRES_AFTER_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Sent by `$user` now: staff viewing as them are kept as ServerOS Support. */
    public function sentBy(User $user): void
    {
        $this->inviter()->associate($user);
        $this->impersonation_session_id = AuditContext::viewAsSession($user);
    }

    /** Who to name as having sent it. */
    public function inviterName(): ?string
    {
        if ($this->impersonation_session_id !== null) {
            $word = __(AuditContext::SUPPORT);

            return is_string($word) ? $word : AuditContext::SUPPORT;
        }

        return $this->inviter?->name;
    }

    /**
     * Give the invitation a fresh token and expiry, returning the plain token
     * for the emailed link.
     */
    public function refreshToken(): string
    {
        $plainToken = Str::random(40);

        $this->forceFill([
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        return $plainToken;
    }

    /**
     * Find a pending invitation by the plain token from its link.
     */
    public static function findByToken(string $plainToken): ?self
    {
        return static::where('token', hash('sha256', $plainToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether the invitation was sent to this user's email address.
     */
    public function isFor(User $user): bool
    {
        return strcasecmp($this->email, $user->email) === 0;
    }
}
