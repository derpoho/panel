<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An app and the resources it uses, on one machine or several, joined by
 * a private network of their own: 10.64.0.0/10 cut into a /24 per project,
 * so no two projects ever share addresses, even on the same machine.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $uid the network's name on each machine
 * @property string $name
 * @property array{enabled?: bool, repo?: string, machine_id?: int, copy_data?: bool, domain?: string|null, limit?: int, user_id?: int}|null $previews pull request previews
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['name'])]
class Project extends Model
{
    /** How many /24s fit in 10.64.0.0/10. */
    public const int MAX_NETWORKS = 16384;

    protected function casts(): array
    {
        return ['previews' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->uid ??= strtolower(Str::random(10));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ProjectNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ProjectNode::class);
    }

    /** @return HasMany<ProjectEnvironment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class)->whereNull('removing_at')->orderBy('position')->orderBy('id');
    }

    /** The first environment, Production, made with the project. */
    public function production(): ProjectEnvironment
    {
        return $this->environments()->first()
            ?? $this->environments()->create(['name' => 'Production', 'position' => 0]);
    }

    /** @return HasMany<ProjectLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class);
    }

    /** @return HasMany<ProjectMachine, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMachine::class);
    }

    /** The first three parts of every address on the network: 10.64.3 */
    public function prefix(): string
    {
        $index = $this->id % self::MAX_NETWORKS;

        return '10.'.(64 + intdiv($index, 256)).'.'.($index % 256);
    }

    public function cidr(): string
    {
        return $this->prefix().'.0/24';
    }

    /** The interface the daemon makes for it (IFNAMSIZ allows 15). */
    public function interface(): string
    {
        return 'sos'.$this->uid;
    }
}
