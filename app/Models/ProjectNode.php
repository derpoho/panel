<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on a project's canvas: a deploy on a machine, by the name it
 * was deployed as. An app, or a resource apps connect to.
 *
 * @property int $id
 * @property int $project_id
 * @property int $machine_id
 * @property string $name
 * @property string $kind app, database, cache, storage or websockets
 * @property string|null $template
 * @property int|null $environment_id
 * @property int|null $origin_id the node it's a copy of, in the environment it came from
 * @property string|null $data_state waiting, copying, copied or failed: its data copied from its origin
 * @property string|null $data_error
 * @property string|null $data_job_id
 * @property CarbonImmutable|null $data_at
 * @property CarbonImmutable|null $removing_at when its removal from the machine was last asked for
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Machine $machine
 * @property-read ProjectEnvironment|null $environment
 * @property-read ProjectNode|null $origin
 */
#[Fillable(['machine_id', 'environment_id', 'name', 'kind', 'template', 'origin_id'])]
class ProjectNode extends Model
{
    public const array KINDS = ['app', 'database', 'cache', 'storage', 'websockets'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    protected function casts(): array
    {
        return ['data_at' => 'immutable_datetime', 'removing_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }

    /** @return BelongsTo<ProjectNode, $this> */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_id');
    }

    public function isApp(): bool
    {
        return $this->kind === 'app';
    }
}
