<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A mail domain a workspace says is its company's (acme.com), proven with
 * a DNS TXT record. Once verified, company Google sign-in, single sign-on
 * and SCIM go by it. Only one workspace can have a domain verified at a
 * time.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $domain lower case, no trailing dot
 * @property string $token
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['domain'])]
class WorkspaceEmailDomain extends Model
{
    /** The subdomain the TXT record can live on, so it doesn't crowd the root's. */
    public const string RECORD_PREFIX = '_serveros-verify';

    /** What the TXT record says, before the token. */
    public const string VALUE_PREFIX = 'serveros-domain-verification=';

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkspaceEmailDomain $domain): void {
            $domain->token = $domain->token ?: Str::lower(Str::random(32));
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Where to add the TXT record: _serveros-verify.acme.com (the root works too). */
    public function recordName(): string
    {
        return self::RECORD_PREFIX.'.'.$this->domain;
    }

    public function recordValue(): string
    {
        return self::VALUE_PREFIX.$this->token;
    }

    /** Whether another workspace has this domain verified right now. */
    public function verifiedElsewhere(): bool
    {
        return self::query()
            ->where('domain', $this->domain)
            ->whereNotNull('verified_at')
            ->where('workspace_id', '!=', $this->workspace_id)
            ->exists();
    }

    /**
     * The domain as the settings page shows it.
     *
     * @return array{id: int, domain: string, verified: bool, verifiedAt: string|null, checkedAt: string|null, record: array{name: string, value: string}}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'verified' => $this->isVerified(),
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'checkedAt' => $this->checked_at?->toIso8601String(),
            'record' => ['name' => $this->recordName(), 'value' => $this->recordValue()],
        ];
    }
}
