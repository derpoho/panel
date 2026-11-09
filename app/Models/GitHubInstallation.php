<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ServerOS GitHub App on someone's account or organisation, connected
 * to a workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $installation_id
 * @property string $account_login the user or organisation, as in its repos' URLs
 * @property string|null $account_type User or Organization
 * @property int|null $connected_by
 */
#[Fillable(['installation_id', 'account_login', 'account_type', 'connected_by'])]
class GitHubInstallation extends Model
{
    protected $table = 'github_installations';

    protected function casts(): array
    {
        return ['installation_id' => 'integer'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
