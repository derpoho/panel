<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One environment of a project: Production, or a copy of another one
 * (Staging) with its own apps and services on machines of its own choosing.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $position
 * @property int|null $source_id the environment it was copied from
 * @property string|null $refresh null, daily or weekly
 * @property int|null $pull_request the pull request it previews
 * @property string|null $head the pull request's commit it runs
 * @property CarbonImmutable|null $refreshed_at
 * @property CarbonImmutable|null $removing_at its apps and services are being removed from their machines
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read ProjectEnvironment|null $source
 */
#[Fillable(['name', 'position', 'source_id', 'refresh'])]
class ProjectEnvironment extends Model
{
    public const array REFRESH = ['daily', 'weekly'];

    protected function casts(): array
    {
        return ['refreshed_at' => 'immutable_datetime', 'removing_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /** @return HasMany<ProjectNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ProjectNode::class, 'environment_id');
    }

    /** Whether its data is due to be copied again from its source. */
    public function refreshDue(CarbonImmutable $now): bool
    {
        if ($this->refresh === null || $this->source_id === null) {
            return false;
        }
        $every = $this->refresh === 'weekly' ? 7 * 24 : 24;

        return $this->refreshed_at === null || $this->refreshed_at->diffInHours($now) >= $every - 1;
    }
}
