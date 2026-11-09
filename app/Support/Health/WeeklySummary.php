<?php

namespace App\Support\Health;

use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\MonitorIncident;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Monitors\MonitorAlerts;

/**
 * The week in a workspace, for people who'd rather read an email on Monday
 * than open a dashboard: how the sites did, what was deployed, whether
 * backups ran, and what's waiting for someone.
 */
class WeeklySummary
{
    public function __construct(private readonly WorkspaceHealth $health) {}

    /**
     * Null when there's nothing to report on (no machines, no monitors).
     *
     * @return array{headline: string, sections: list<array{title: string, lines: list<string>}>}|null
     */
    public function build(Workspace $workspace): ?array
    {
        $machines = $workspace->machines()->get();
        $monitors = $workspace->monitors()->where('paused', false)->orderBy('name')->get();
        if ($machines->isEmpty() && $monitors->isEmpty()) {
            return null;
        }
        $since = now()->subDays(7);
        $sections = [];

        if ($monitors->isNotEmpty()) {
            $incidents = MonitorIncident::query()->whereIn('monitor_id', $monitors->modelKeys())->where('started_at', '>=', $since)->get()->groupBy('monitor_id');
            $lines = array_values($monitors->map(function ($monitor) use ($incidents) {
                $uptime = $monitor->uptime(7);
                $down = $incidents[$monitor->id] ?? collect();
                $seconds = (int) $down->sum(fn ($i) => $i->started_at->diffInSeconds($i->resolved_at ?? now()));

                return "{$monitor->name}: ".($uptime === null ? 'no checks' : rtrim(rtrim(number_format($uptime, 2), '0'), '.').'% up')
                    .($down->isNotEmpty() ? ', down '.$down->count().' '.($down->count() === 1 ? 'time' : 'times').' for '.MonitorAlerts::duration($seconds) : '');
            })->all());
            $sections[] = ['title' => 'Uptime', 'lines' => $lines];
        }

        $deploys = DaemonJob::query()->whereIn('machine_id', $machines->modelKeys())->where('kind', 'deploy')->where('created_at', '>=', $since)->get(['state', 'service']);
        $backups = Backup::query()->whereIn('machine_id', $machines->modelKeys())->where('created_at', '>=', $since)->get(['state']);
        $runs = WorkflowRun::query()->whereIn('workflow_id', $workspace->workflows()->pluck('id'))->where('started_at', '>=', $since)->get(['state']);
        $work = array_values(array_filter([
            $deploys->isEmpty() ? null : self::count($deploys->where('state', 'succeeded')->count(), 'deploy')
                .($deploys->where('state', 'failed')->count() ? ', '.$deploys->where('state', 'failed')->count().' failed' : '')
                .' ('.$deploys->pluck('service')->filter()->unique()->take(4)->implode(', ').')',
            $backups->isEmpty() ? 'No backups ran.' : self::count($backups->where('state', 'succeeded')->count(), 'backup')
                .($backups->where('state', 'failed')->count() ? ', '.$backups->where('state', 'failed')->count().' failed' : ''),
            $runs->isEmpty() ? null : self::count($runs->count(), 'workflow run').($runs->where('state', 'failed')->count() ? ', '.$runs->where('state', 'failed')->count().' failed' : ''),
        ]));
        $sections[] = ['title' => 'This week', 'lines' => $work];

        $issues = array_values(array_filter($this->health->issues($workspace, $machines), fn ($i) => $i['severity'] !== WorkspaceHealth::NOTICE));
        $sections[] = [
            'title' => 'Needs attention',
            'lines' => $issues === [] ? ['Nothing right now.'] : array_map(fn ($i) => $i['title'], array_slice($issues, 0, 6)),
        ];

        $downtime = $monitors->isEmpty() ? null : MonitorIncident::query()->whereIn('monitor_id', $monitors->modelKeys())->where('started_at', '>=', $since)->count();
        $headline = match (true) {
            $issues !== [] => count($issues).' '.(count($issues) === 1 ? 'thing needs' : 'things need').' attention',
            $downtime === 0 => 'No downtime this week',
            default => 'Everything is running',
        };

        return ['headline' => $headline, 'sections' => $sections];
    }

    private static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
