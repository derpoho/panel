<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time join command for a machine, and who made it: the machine
 * joining is credited to them when it enrols.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $machine_id
 * @property int|null $user_id who made the command
 * @property int|null $impersonation_session_id the View as they made it in, if staff did
 * @property int|null $machine_policy_id the policy the machine joins with, if the command chose one
 * @property int|null $machine_group_id the group the machine joins, if the command chose one
 * @property array<string, mixed>|null $metadata its provider, region, tier and labels, if the command gave them
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property-read User|null $user
 */
class DaemonEnrolment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

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
}
