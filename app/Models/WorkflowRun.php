<?php

namespace App\Models;

use App\Models\Concerns\CreditsWhoStartedIt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a workflow: where each step got to, and what it said.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id set when staff started it while viewing as someone
 * @property string $state running, succeeded, failed or cancelled
 * @property array{kind: string, summary: string, data?: array<string, mixed>} $trigger
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property array<string, array<string, mixed>> $steps
 * @property list<string> $frontier
 * @property list<array{t: int, node: string|null, level: string, message: string}> $logs
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $wake_at
 * @property-read Workflow $workflow
 * @property-read User|null $user
 */
class WorkflowRun extends Model
{
    use CreditsWhoStartedIt;

    public const KEEP_DAYS = 30;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger' => 'array',
            'graph' => 'array',
            'steps' => 'array',
            'frontier' => 'array',
            'logs' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'wake_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function finished(): bool
    {
        return in_array($this->state, ['succeeded', 'failed', 'cancelled'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'trigger' => $this->trigger,
            'user' => $this->startedByName(),
            'steps' => (object) $this->steps,
            'logs' => $this->logs,
            'startedAt' => $this->started_at->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'seconds' => (int) $this->started_at->diffInSeconds($this->finished_at ?? now()),
        ];
    }
}
