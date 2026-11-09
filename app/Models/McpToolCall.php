<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool an agent called through MCP. Kept for 30 days.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $mcp_token_id
 * @property string|null $call_id given as the call starts; server jobs it queues carry it (daemon_jobs.mcp_call_id)
 * @property string $tool
 * @property array<string, mixed>|null $arguments
 * @property bool $ok
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property-read McpToken|null $token
 */
class McpToolCall extends Model
{
    public const KEEP_DAYS = 30;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'ok' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<McpToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }
}
