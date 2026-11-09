<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of time a monitor was down, from the check that confirmed it
 * to the first one that passed again.
 *
 * @property int $id
 * @property int $monitor_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property string $cause
 * @property-read Monitor $monitor
 */
class MonitorIncident extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @return array{id: int, startedAt: string, resolvedAt: string|null, cause: string, seconds: int}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'startedAt' => $this->started_at->toIso8601String(),
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'cause' => $this->cause,
            'seconds' => (int) $this->started_at->diffInSeconds($this->resolved_at ?? now()),
        ];
    }
}
