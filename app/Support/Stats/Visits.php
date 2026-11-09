<?php

namespace App\Support\Stats;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Notes that someone used a workspace today: once a day per person and
 * workspace, so a busy afternoon is still one row. Never gets in the way
 * of the request it's counting.
 */
class Visits
{
    public static function record(int $workspaceId, int $userId, string $source = 'panel'): void
    {
        $day = now()->toDateString();

        try {
            if (! Cache::add("visit:{$workspaceId}:{$userId}:{$day}", true, now()->endOfDay())) {
                return;
            }

            DB::table('workspace_visits')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'day' => $day,
                'source' => $source,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
