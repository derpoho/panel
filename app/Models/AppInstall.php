<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One install from the app installer: which template, on which machine,
 * by whom, and the deploy job that did it (for whether it worked).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $machine_id
 * @property int|null $user_id
 * @property string|null $daemon_job_id
 * @property string $template
 * @property string $source
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'machine_id', 'user_id', 'daemon_job_id', 'template', 'source', 'created_at'])]
class AppInstall extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(Machine $machine, ?User $user, string $template, ?string $jobId, string $source): void
    {
        self::query()->create([
            'workspace_id' => $machine->workspace_id,
            'machine_id' => $machine->id,
            'user_id' => $user?->id,
            'daemon_job_id' => $jobId,
            'template' => $template,
            'source' => $source,
        ]);
    }
}
