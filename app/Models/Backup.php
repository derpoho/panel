<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service's backup: the daemon job that took it and sent it off, and
 * where it ended up.
 *
 * @property int $id
 * @property int $machine_id
 * @property int|null $backup_destination_id
 * @property string|null $daemon_job_id
 * @property string|null $snapshot the daemon's snapshot id, kept on the machine for a while
 * @property string $service inventory key
 * @property string $service_name
 * @property string $trigger manual, scheduled, workflow, or pre-restore for the copy taken before a restore
 * @property string $state queued, running, succeeded, failed or skipped
 * @property int|null $bytes
 * @property string|null $location
 * @property string|null $error
 * @property string|null $restore_job_id its latest restore
 * @property string|null $restore_state queued, running, succeeded or failed
 * @property string|null $restore_error
 * @property CarbonImmutable|null $restored_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 * @property-read BackupDestination|null $destination
 */
#[Fillable(['backup_destination_id', 'daemon_job_id', 'service', 'service_name', 'trigger', 'state'])]
class Backup extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['finished_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'machine' => ['id' => $this->machine_id, 'name' => $this->machine->name],
            'destination' => $this->destination?->name,
            'service' => $this->service,
            'serviceName' => $this->service_name,
            'trigger' => $this->trigger,
            'state' => $this->state,
            'bytes' => $this->bytes,
            'location' => $this->location,
            'error' => $this->error,
            'restore' => $this->restore_state === null ? null : [
                'state' => $this->restore_state,
                'error' => $this->restore_error,
                'at' => $this->restored_at?->toIso8601String(),
            ],
            'createdAt' => $this->created_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
        ];
    }
}
