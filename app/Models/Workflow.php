<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A trigger and the steps that follow it, drawn on the canvas.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $run_as
 * @property string $name
 * @property string|null $description
 * @property bool $enabled
 * @property string $trigger
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property string|null $webhook_hash
 * @property string|null $webhook_token
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $runAs
 */
class Workflow extends Model
{
    /** Event runs of one workflow at least this far apart: stops loops. */
    public const COOLDOWN_SECONDS = 120;

    /** And at most this many an hour, whatever starts them. */
    public const RUNS_PER_HOUR = 30;

    protected $guarded = ['id', 'workspace_id'];

    protected $hidden = ['webhook_token', 'webhook_hash'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'graph' => 'array',
            'webhook_token' => 'encrypted',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function runAs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_as');
    }

    /**
     * @return HasMany<WorkflowRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * The trigger step, or null on an empty canvas.
     *
     * @return array<string, mixed>|null
     */
    public function triggerNode(): ?array
    {
        foreach ($this->graph['nodes'] ?? [] as $node) {
            if (($node['kind'] ?? null) === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerRules(): array
    {
        return $this->triggerNode()['rules'] ?? [];
    }

    /**
     * A new webhook token, returned once in full and kept encrypted.
     */
    public function rotateWebhook(): string
    {
        $token = 'wh_'.Str::random(40);
        $this->webhook_token = $token;
        $this->webhook_hash = hash('sha256', $token);

        return $token;
    }

    public function webhookUrl(): ?string
    {
        return $this->webhook_token === null ? null : rtrim((string) config('app.url'), '/').'/api/hooks/workflows/'.$this->webhook_token;
    }
}
