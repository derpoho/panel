<?php

namespace App\Models;

use App\Mcp\Scopes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A way into one workspace's MCP server, acting as one person: an API key
 * they made in settings, or what an AI app was given through OAuth. (Or
 * into a package's own MCP server, for a package's OAuth audience.) The
 * secret itself is only ever shown once; the table holds its SHA-256.
 *
 * @property int $id
 * @property string $audience workspace, api, or a package's OAuth audience (App\Support\Mcp\OAuthAudience), whose tokens may have no workspace
 * @property int|null $workspace_id
 * @property int $user_id
 * @property int|null $mcp_oauth_client_id
 * @property string $kind key or oauth
 * @property string $name
 * @property list<string> $scopes
 * @property string $token_hash
 * @property string $token_prefix
 * @property string|null $refresh_hash
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $refresh_expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_ip
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 * @property-read McpOAuthClient|null $client
 */
class McpToken extends Model
{
    /** API keys start with this, so they're easy to spot in a leak scan. */
    public const KEY_PREFIX = 'sos_';

    /** OAuth access tokens: short, with a refresh token to renew them. */
    public const ACCESS_MINUTES = 60;

    public const REFRESH_DAYS = 30;

    /** What a token is for: one workspace's MCP server (or a package's OAuth audience's key). */
    public const WORKSPACE = 'workspace';

    /** The REST API: a key for one workspace, used by scripts and CI. */
    public const API = 'api';

    protected $guarded = ['id'];

    protected $attributes = ['audience' => self::WORKSPACE];

    protected $hidden = ['token_hash', 'refresh_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<McpOAuthClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOAuthClient::class, 'mcp_oauth_client_id');
    }

    /**
     * Not revoked. Expiry is checked separately: a refresh token can outlive
     * its access token.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * A new random secret, with the prefix that says what it is.
     */
    public static function secret(string $kind): string
    {
        return match ($kind) {
            'key' => self::KEY_PREFIX.Str::random(40),
            'refresh' => 'sor_'.Str::random(48),
            default => 'soa_'.Str::random(48),
        };
    }

    /**
     * Make an API key. Returns the record and the key, which is the only
     * time the key exists in clear.
     *
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issueKey(Workspace $workspace, User $user, string $name, array $scopes, string $audience = self::WORKSPACE): array
    {
        $secret = self::secret('key');
        $token = self::query()->create([
            'audience' => $audience,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'kind' => 'key',
            'name' => $name,
            'scopes' => Scopes::normalise($scopes),
            'token_hash' => self::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
        ]);

        return [$token, $secret];
    }

    /**
     * Find the live token a bearer secret belongs to, if any.
     */
    public static function findActive(string $secret): ?self
    {
        $token = self::query()->active()->where('token_hash', self::hash($secret))->first();
        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now(), 'refresh_hash' => null])->save();
        }
    }

    /** What to call it in lists: the app's name for OAuth, the key's own name otherwise. */
    public function label(): string
    {
        return $this->kind === 'oauth' ? ($this->client->name ?? $this->name) : $this->name;
    }
}
