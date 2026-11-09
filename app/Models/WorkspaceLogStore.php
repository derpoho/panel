<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace's own Postgres for its logs. See LogStore.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $driver pgsql (sqlite only in tests)
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $username
 * @property string $password encrypted at rest
 * @property bool $enabled
 * @property bool $prune
 * @property string $status ok or unreachable
 * @property string|null $last_error
 * @property Carbon|null $last_ok_at
 * @property-read Workspace $workspace
 */
class WorkspaceLogStore extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'enabled' => 'boolean',
            'prune' => 'boolean',
            'last_ok_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
