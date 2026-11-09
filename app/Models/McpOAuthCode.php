<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $code_hash
 * @property int $mcp_oauth_client_id
 * @property int $user_id
 * @property string $audience workspace, or a package's OAuth audience (App\Support\Mcp\OAuthAudience)
 * @property int|null $workspace_id null for an audience that isn't a workspace
 * @property string $redirect_uri
 * @property string $code_challenge
 * @property list<string> $scopes
 * @property string $resource
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property-read McpOAuthClient $client
 * @property-read User $user
 * @property-read Workspace|null $workspace
 */
class McpOAuthCode extends Model
{
    protected $table = 'mcp_oauth_codes';

    protected $guarded = ['id'];

    protected $attributes = ['audience' => McpToken::WORKSPACE];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<McpOAuthClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOAuthClient::class, 'mcp_oauth_client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
