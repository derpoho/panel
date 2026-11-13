<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $version
 * @property string $from_version
 * @property string $status
 * @property int|null $workspace_id
 * @property int|null $requested_by
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Workspace|null $workspace
 * @property-read User|null $requester
 */
class PanelUpdate extends Model
{
    public const REQUESTED = 'requested';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function pending(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::RUNNING], true);
    }

    public function fail(string $error): void
    {
        $this->update(['status' => self::FAILED, 'error' => mb_substr($error, 0, 500), 'finished_at' => now()]);
    }
}
