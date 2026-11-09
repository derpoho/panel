<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of a workspace's machines, shown as one row that opens up.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property int|null $machine_policy_id the policy its machines follow, unless one picked its own
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['name'])]
class MachineGroup extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<MachinePolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(MachinePolicy::class, 'machine_policy_id');
    }

    /** @return HasMany<Machine, $this> */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}
