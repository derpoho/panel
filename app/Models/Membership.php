<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person's place in a workspace: the user_workspace row behind
 * $user->workspaces and $workspace->members, read as ->pivot. Columns
 * past role are only there when the query asked for them with withPivot().
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $role
 * @property int|null $custom_role_id
 * @property string|null $onboarding_dismissed_at
 * @property string|null $pinned_at
 * @property string|null $alert_emails all, problems or none
 * @property int|null $weekly_summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Membership extends Pivot
{
    protected $table = 'user_workspace';

    public $incrementing = true;
}
