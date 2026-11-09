<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One investigation by the server agent: what it read, what it thinks is
 * wrong, the steps it proposes, and how they went once someone approved.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $machine_id
 * @property string|null $service_key
 * @property string|null $service_name
 * @property int|null $user_id
 * @property string $source
 * @property string|null $note
 * @property string $state
 * @property string|null $title
 * @property string|null $cause
 * @property string|null $explanation
 * @property string|null $confidence
 * @property array<string, mixed>|null $evidence
 * @property list<array<string, mixed>>|null $actions
 * @property list<array<string, mixed>>|null $results
 * @property string|null $model
 * @property string|null $error
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property-read Workspace $workspace
 * @property-read Machine $machine
 * @property-read User|null $user
 * @property-read User|null $decider
 */
class AgentFix extends Model
{
    /** Waiting on the agent or the machine. */
    public const ACTIVE = ['investigating', 'running'];

    /** Done one way or another. */
    public const FINISHED = ['nothing_to_do', 'fixed', 'failed', 'declined', 'error'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'actions' => 'array',
            'results' => 'array',
            'decided_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<DaemonJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(DaemonJob::class);
    }
}
