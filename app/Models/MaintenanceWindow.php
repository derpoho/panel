<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maintenance planned ahead on one machine. Shown on its status page as
 * upcoming, started on time by maintenance:tick (which puts the machine in
 * maintenance and runs the work), and finished when the time is up, or
 * early once a restart is done and the machine is back.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $machine_id
 * @property int|null $created_by
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 * @property bool $public
 * @property string $action none, reboot or updates
 * @property string $state scheduled, active, done or cancelled
 * @property string|null $phase updating, rebooting or back, while active with work
 * @property string|null $job_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $outcome
 * @property-read Machine $machine
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class MaintenanceWindow extends Model
{
    public const ACTIONS = ['none', 'reboot', 'updates'];

    /** How far ahead a window can be planned. */
    public const MAX_DAYS_AHEAD = 60;

    /** How far ahead status pages show what's planned. */
    public const SHOWN_DAYS_AHEAD = 14;

    protected $guarded = ['id'];

    /** The table's defaults, so a window just made says them too. */
    protected $attributes = ['state' => 'scheduled', 'action' => 'none', 'public' => true];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'public' => 'boolean',
        ];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Planned and not started, starting within the days given.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query, int $days = self::SHOWN_DAYS_AHEAD): void
    {
        $query->where('state', 'scheduled')->where('starts_at', '<=', now()->addDays($days))->orderBy('starts_at');
    }

    public function minutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /**
     * How the panel and status pages read it.
     *
     * @return array<string, mixed>
     */
    public function toCard(bool $private = true): array
    {
        return [
            'id' => $this->id,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at->toIso8601String(),
            'reason' => $this->reason,
            'state' => $this->state,
            ...($private ? [
                'action' => $this->action,
                'public' => $this->public,
                'phase' => $this->phase,
                'outcome' => $this->outcome,
                'by' => $this->creator?->name,
            ] : []),
        ];
    }
}
