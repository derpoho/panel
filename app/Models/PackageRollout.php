<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OS updates installed on many machines: the latest of chosen packages
 * (or everything waiting), or exact versions, all at once or in batches.
 * PackageRollouts moves it along every minute.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property list<array{name: string, version: string|null}> $packages empty for every waiting update
 * @property string $mode latest or exact
 * @property bool $allow_downgrade
 * @property int|null $batch_size null for all at once
 * @property bool $stop_on_failure
 * @property string $reboot never or if_required
 * @property CarbonImmutable|null $scheduled_for
 * @property string $state pending, running, paused, done, failed or cancelled
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class PackageRollout extends Model
{
    public const array MODES = ['latest', 'exact'];

    public const array REBOOTS = ['never', 'if_required'];

    /** States it can still move from. */
    public const array OPEN = ['pending', 'running', 'paused'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'allow_downgrade' => 'boolean',
            'stop_on_failure' => 'boolean',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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

    /** @return HasMany<PackageRolloutMachine, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PackageRolloutMachine::class)->orderBy('position');
    }

    /** Every waiting update, rather than chosen packages. */
    public function everything(): bool
    {
        return $this->packages === [];
    }
}
