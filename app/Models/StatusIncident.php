<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An incident written for status pages: a title, how bad it is, and
 * updates as it's worked on, from investigating to resolved. On a
 * machine's page and the workspace's when it's about one machine, else on
 * the workspace's page only.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $machine_id
 * @property string $title
 * @property string $impact degraded, partial or major
 * @property string $state the latest update's
 * @property int|null $user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Machine|null $machine
 * @property-read Collection<int, StatusIncidentUpdate> $updates
 */
class StatusIncident extends Model
{
    public const IMPACTS = ['degraded', 'partial', 'major'];

    public const STATES = ['investigating', 'identified', 'monitoring', 'resolved'];

    /** Resolved incidents stay on the page this long. */
    public const KEEP_DAYS = 14;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return HasMany<StatusIncidentUpdate, $this> newest first */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusIncidentUpdate::class)->latest('created_at')->latest('id');
    }

    public function open(): bool
    {
        return $this->resolved_at === null;
    }

    /**
     * Open ones, and ones resolved in the last KEEP_DAYS: what a page shows.
     *
     * @param  Builder<StatusIncident>  $query
     * @return Builder<StatusIncident>
     */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays(self::KEEP_DAYS)));
    }

    /**
     * As a status page shows it.
     *
     * @return array{id: int, title: string, impact: string, state: string, machine: string|null, startedAt: string, resolvedAt: string|null, updates: list<array{state: string, message: string, at: string}>}
     */
    public function toReport(bool $withMachine = false): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'impact' => $this->impact,
            'state' => $this->state,
            'machine' => $withMachine ? $this->machine?->name : null,
            'startedAt' => $this->started_at->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'updates' => array_values($this->updates->map(fn (StatusIncidentUpdate $u) => [
                'state' => $u->state,
                'message' => $u->message,
                'at' => ($u->created_at ?? now())->toIso8601String(),
            ])->all()),
        ];
    }
}
