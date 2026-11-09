<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a Business workspace's own domains. The dashboard's is a hostname
 * (ops.acme.com); the status page's is either a hostname of its own
 * (status.acme.com) or a path on the dashboard's (path "status").
 * Hostnames go through Cloudflare for SaaS: pending until Cloudflare has
 * checked the customer's DNS and issued a certificate, then active.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $purpose dashboard or status
 * @property string|null $hostname
 * @property string|null $path status pages only: served at /{path} on the dashboard's domain
 * @property string|null $status_page_slug status pages only: which page; null for the fleet page
 * @property string|null $cloudflare_id
 * @property string $state pending, active or failed
 * @property string|null $ssl_state Cloudflare's certificate status
 * @property array<string, mixed>|null $verification DNS records the customer still needs
 * @property string|null $error
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class WorkspaceDomain extends Model
{
    public const DASHBOARD = 'dashboard';

    public const STATUS = 'status';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verification' => 'array',
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function active(): bool
    {
        return $this->state === 'active';
    }

    /** A status page on the dashboard's domain, rather than a hostname of its own. */
    public function onPath(): bool
    {
        return $this->hostname === null && $this->path !== null;
    }
}
