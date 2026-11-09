<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine on a project's network: its address there, the UDP port its
 * WireGuard listens on, and the public key it reported (its private key
 * never leaves the machine).
 *
 * @property int $id
 * @property int $project_id
 * @property int $machine_id
 * @property int $host
 * @property int $listen_port
 * @property string|null $public_key
 * @property string $state pending, up, failed or offline
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Machine $machine
 */
class ProjectMachine extends Model
{
    /** The first port networks listen on; each machine's next is free. */
    public const int FIRST_PORT = 51820;

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

    /** Its address on the network, without the prefix length. */
    public function address(): string
    {
        return $this->project->prefix().'.'.$this->host;
    }
}
