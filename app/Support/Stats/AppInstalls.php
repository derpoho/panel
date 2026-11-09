<?php

namespace App\Support\Stats;

use App\Models\AppInstall;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What people install with the app installer: each app's installs in the
 * range, how many workspaces, and how many of those deploys worked.
 */
final class AppInstalls
{
    /**
     * @return array{total: int, workspaces: int, allTime: int, since: string|null, rows: list<array{slug: string, name: string, icon: string|null, category: string|null, installs: int, workspaces: int, succeeded: int, failed: int, last: string|null}>}
     */
    public static function summary(CarbonInterface $since): array
    {
        $rows = AppInstall::query()
            ->from('app_installs')
            ->leftJoin('daemon_jobs', 'daemon_jobs.id', '=', 'app_installs.daemon_job_id')
            ->where('app_installs.created_at', '>=', $since)
            ->groupBy('app_installs.template')
            ->select([
                'app_installs.template',
                DB::raw('count(*) as installs'),
                DB::raw('count(distinct app_installs.workspace_id) as workspaces'),
                DB::raw("sum(case when daemon_jobs.state = 'succeeded' then 1 else 0 end) as succeeded"),
                DB::raw("sum(case when daemon_jobs.state in ('failed', 'timed_out', 'refused') then 1 else 0 end) as failed"),
                DB::raw('max(app_installs.created_at) as last'),
            ])
            ->orderByDesc('installs')
            ->orderBy('app_installs.template')
            ->toBase()
            ->get();

        $catalog = app(ServiceTemplateCatalog::class);
        // The raw column, like each row's last below.
        $first = AppInstall::query()->min('created_at');

        return [
            'total' => (int) $rows->sum('installs'),
            'workspaces' => AppInstall::query()->where('created_at', '>=', $since)->distinct()->count('workspace_id'),
            'allTime' => AppInstall::query()->count(),
            'since' => $first === null ? null : (string) $first,
            'rows' => array_values($rows->map(function (object $row) use ($catalog): array {
                $template = $catalog->find((string) $row->template);

                return [
                    'slug' => (string) $row->template,
                    'name' => $template->name ?? (string) $row->template,
                    'icon' => $template?->icon,
                    'category' => $template?->category,
                    'installs' => (int) $row->installs,
                    'workspaces' => (int) $row->workspaces,
                    'succeeded' => (int) $row->succeeded,
                    'failed' => (int) $row->failed,
                    'last' => $row->last === null ? null : (string) $row->last,
                ];
            })->all()),
        ];
    }
}
