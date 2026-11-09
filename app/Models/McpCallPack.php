<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra AI tool calls a workspace bought, added to the month it bought
 * them in.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $period the month, as 2026-10
 * @property int $calls
 * @property int $amount pence paid
 * @property CarbonImmutable|null $created_at
 */
class McpCallPack extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['calls' => 'integer', 'amount' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
