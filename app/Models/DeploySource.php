<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project uploaded as a .zip to deploy from. Once unpacked it lives as a
 * bare Git repository under storage, served to the daemon by its token
 * (see GitSourceController), and is pruned when it expires, unless it's
 * what a live app was built from: that one is kept so the app can be
 * redeployed (a new domain, say) without uploading it again.
 *
 * @property int $id
 * @property string $token
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $filename
 * @property int $size bytes the browser said it would send
 * @property int $received bytes received so far
 * @property string $status uploading, ready or failed
 * @property string|null $commit
 * @property int|null $files
 * @property array<string, mixed>|null $detection
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['token', 'filename', 'size', 'received', 'status', 'commit', 'files', 'detection', 'expires_at'])]
class DeploySource extends Model
{
    /** The biggest .zip accepted. */
    public const MAX_BYTES = 1024 * 1024 * 1024;

    /** Each upload request carries at most this much (under PHP's 8 MB post limit). */
    public const CHUNK_BYTES = 5 * 1024 * 1024;

    /** How long an upload can be deployed from. */
    public const KEEP_HOURS = 24;

    protected function casts(): array
    {
        return ['detection' => 'array', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function directory(): string
    {
        return storage_path('app/private/deploy-sources/'.$this->token);
    }

    public function zipPath(): string
    {
        return $this->directory().'/upload.zip';
    }

    public function repoPath(): string
    {
        return $this->directory().'/repo.git';
    }

    /** The upload a deploy's repository address points at, if it is one. */
    public static function fromRepoUrl(?string $url): ?self
    {
        if (! preg_match('#/api/deploy-sources/([A-Za-z0-9]{48})\.git$#', (string) $url, $match)) {
            return null;
        }

        return self::query()->where('token', $match[1])->first();
    }

    /** The HTTPS address the daemon clones from, on the main domain. */
    public function repoUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/deploy-sources/'.$this->token.'.git';
    }

    public function usable(): bool
    {
        return $this->status === 'ready' && $this->expires_at->isFuture();
    }
}
