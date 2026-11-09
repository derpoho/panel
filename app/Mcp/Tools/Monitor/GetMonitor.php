<?php

namespace App\Mcp\Tools\Monitor;

use App\Http\Controllers\MonitorController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use DateTimeInterface;
use Illuminate\Validation\Rule;

/**
 * One monitor as its page shows it: status, uptime, response times,
 * recent checks and incidents, and whether its alerts would go out.
 */
final class GetMonitor extends Tool
{
    /** Points in the response time series. */
    public const POINTS = 24;

    public function name(): string
    {
        return 'get_monitor';
    }

    public function title(): string
    {
        return 'Get a monitor';
    }

    public function description(): string
    {
        return 'Returns one uptime monitor as its page shows it: what it checks and every setting (interval, timeout, expected status, keyword, linked machine), its status and the last error, uptime (last 24 h and 7 days from its checks, 30 and 90 days from daily totals; percent, null before any check), daily uptime for the last `days` days (default 30, up to 90), response times over `range` (24h default, or 7d: average, fastest and slowest of passing checks, failed checks, and a 24-point series), the latest checks (default 20) and incidents (stretches it was down, with cause and length; openIncident is the current one), the HTTPS certificate’s expiry, and alertState: '
            .'whether alerts are on for it, muted (until when, or because its machine is in maintenance), which channels the workspace sends to, and wouldAlert (an alert would go out now if it went down). Checks are kept for 7 days.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'monitor' => Monitors::schema(),
            'range' => Schema::string('Response times over the last 24h (default) or 7d.', array_keys(MonitorController::RANGES)),
            'checks' => Schema::integer('How many of the latest checks to include (default 20).', 0, 100),
            'incidents' => Schema::integer('How many of the latest incidents to include (default 20).', 0, 50),
            'days' => Schema::integer('Daily uptime for this many days, oldest first (default 30).', 1, Monitor::DAYS),
        ], ['monitor']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'monitor' => ['required', Monitors::reference()],
            'range' => ['nullable', Rule::in(array_keys(MonitorController::RANGES))],
            'checks' => ['nullable', 'integer', 'between:0,100'],
            'incidents' => ['nullable', 'integer', 'between:0,50'],
            'days' => ['nullable', 'integer', 'between:1,'.Monitor::DAYS],
        ]);
        $monitor = Monitors::find($context, $data['monitor']);
        $range = $data['range'] ?? '24h';
        $workspace = $context->workspace;
        $email = (bool) ($workspace->alert_email ?? true);
        $webhook = filled($workspace->alert_webhook_url);
        $alerts = Monitors::alerts($monitor);
        $open = $monitor->openIncident()->first();

        return [
            'monitor' => Monitors::detail($monitor, $context),
            'uptime' => [
                'last24Hours' => $this->uptimeSince($monitor, now()->subDay()),
                'last7Days' => $this->uptimeSince($monitor, now()->subWeek()),
                'last30Days' => $monitor->uptime(30),
                'last90Days' => $monitor->uptime(Monitor::DAYS),
            ],
            'days' => $monitor->days((int) ($data['days'] ?? 30)),
            'responseTime' => $this->responseTime($monitor, $range),
            'openIncident' => $open?->toPageProps(),
            'incidents' => $monitor->incidents()->latest('started_at')->limit((int) ($data['incidents'] ?? 20))->get()
                ->map(fn (MonitorIncident $i) => [...$i->toPageProps(), 'ongoing' => $i->resolved_at === null])->values()->all(),
            'checks' => $monitor->checks()->latest('checked_at')->limit((int) ($data['checks'] ?? 20))->get()
                ->map(fn (MonitorCheck $c) => [
                    'at' => $c->checked_at->toIso8601String(),
                    'ok' => $c->ok,
                    'ms' => $c->response_ms,
                    'statusCode' => $c->status_code,
                    'error' => $c->error,
                ])->values()->all(),
            'certificate' => $monitor->isHttps() ? [
                'expiresAt' => $monitor->ssl_expires_at?->toIso8601String(),
                'daysLeft' => $monitor->ssl_expires_at !== null ? (int) floor(now()->diffInDays($monitor->ssl_expires_at, false)) : null,
                'checkedAt' => $monitor->ssl_checked_at?->toIso8601String(),
            ] : null,
            'alertState' => [
                ...$alerts,
                'channels' => ['email' => $email, 'webhook' => $webhook],
                'wouldAlert' => $monitor->alerts && ! $alerts['muted'] && ! $monitor->paused && ($email || $webhook),
            ],
        ];
    }

    /**
     * Uptime from the checks since a time, as a percentage, as the
     * monitor's page works it out.
     */
    private function uptimeSince(Monitor $monitor, DateTimeInterface $since): ?float
    {
        $row = $monitor->checks()->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total, sum(case when ok then 1 else 0 end) as passed')
            ->toBase()->first();

        return $row === null || (int) $row->total === 0 ? null : round((int) $row->passed / (int) $row->total * 100, 3);
    }

    /**
     * Response times of the checks over the range, and a short series of
     * them.
     *
     * @return array<string, mixed>
     */
    private function responseTime(Monitor $monitor, string $range): array
    {
        $hours = MonitorController::RANGES[$range];
        $end = now()->getTimestamp();
        $start = $end - $hours * 3600;
        $size = intdiv($hours * 3600, self::POINTS);
        $buckets = array_fill(0, self::POINTS, [0, 0, 0]);
        $times = [];
        $failed = 0;

        $checks = $monitor->checks()->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at')
            ->get(['checked_at', 'ok', 'response_ms']);

        foreach ($checks as $check) {
            $i = min(self::POINTS - 1, max(0, intdiv($check->checked_at->getTimestamp() - $start, $size)));
            if ($check->ok && $check->response_ms !== null) {
                $times[] = $check->response_ms;
                $buckets[$i][0] += $check->response_ms;
                $buckets[$i][1]++;
            }
            if (! $check->ok) {
                $failed++;
                $buckets[$i][2]++;
            }
        }

        return [
            'range' => $range,
            'checks' => $checks->count(),
            'failed' => $failed,
            'averageMs' => $times === [] ? null : (int) round(array_sum($times) / count($times)),
            'fastestMs' => $times === [] ? null : min($times),
            'slowestMs' => $times === [] ? null : max($times),
            'series' => array_map(fn (int $i) => [
                'from' => date(DATE_ATOM, $start + $i * $size),
                'averageMs' => $buckets[$i][1] > 0 ? (int) round($buckets[$i][0] / $buckets[$i][1]) : null,
                'failed' => $buckets[$i][2],
            ], range(0, self::POINTS - 1)),
        ];
    }
}
