<?php

namespace App\Mcp\Tools\Log;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\StatusPages\StatusReport;
use Carbon\CarbonImmutable;

/**
 * How much the workspace's services logged over a window, by level, by
 * service and over time: where to look before reading lines.
 */
final class GetLogStats extends Tool
{
    public const DEFAULT_TOP = 25;

    public function name(): string
    {
        return 'get_log_stats';
    }

    public function title(): string
    {
        return 'Count log lines';
    }

    public function description(): string
    {
        return 'Counts the log lines the workspace’s services wrote over a window (default the last 24 hours), to see which services are noisy or failing before reading lines with search_logs. '
            .'Returns the total, counts by level (error, warn, info, debug), each machine’s counts, the busiest services (most errors first, then warnings, then lines; up to top, default '.self::DEFAULT_TOP.') with their machine, service name, label, counts by level and when they last wrote a line and an error, and a timeline of lines, warnings and errors per bucket (5 minutes to a day, by the window’s length). '
            .'Takes the same filters as search_logs: machine, service, level, q, since and until.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            ...LogQuery::schema(' Default: a day before until (or now).'),
            'top' => Schema::integer('How many services to list (1–100, default '.self::DEFAULT_TOP.').', 1, 100),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return LogQuery::reachable($context->workspace, fn () => $this->stats($context, $arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function stats(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...LogQuery::rules(),
            'top' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // The window ends at until, or now, and starts a day before unless said.
        if (! isset($data['since'])) {
            $end = isset($data['until']) ? LogQuery::time('until', (string) $data['until'], true) : CarbonImmutable::now()->utc();
            $data['since'] = $end->subDay()->toIso8601String();
        }

        $filters = LogQuery::from($context, $data);
        /** @var CarbonImmutable $since */
        $since = $filters->since;
        $until = $filters->until ?? CarbonImmutable::now()->utc();
        $names = $filters->machines->pluck('name', 'id')->all();
        $top = (int) ($data['top'] ?? self::DEFAULT_TOP);

        $groups = $filters->query()
            ->selectRaw('machine_id, service, level, count(*) as n, max(logged_at) as last_at')
            ->groupBy('machine_id', 'service', 'level')
            ->toBase()
            ->get();

        $byLevel = array_fill_keys(LogQuery::LEVELS, 0);
        $services = [];
        $machines = [];
        foreach ($groups as $row) {
            $count = (int) $row->n;
            $level = in_array($row->level, LogQuery::LEVELS, true) ? $row->level : 'info';
            $byLevel[$level] += $count;

            $key = $row->machine_id.'|'.$row->service;
            $services[$key] ??= [
                'machine' => ['id' => (int) $row->machine_id, 'name' => $names[$row->machine_id] ?? null],
                'service' => $row->service,
                'label' => StatusReport::friendlyName($row->service),
                'lines' => 0,
                ...array_fill_keys(LogQuery::LEVELS, 0),
                'lastAt' => null,
                'lastErrorAt' => null,
            ];
            $services[$key]['lines'] += $count;
            $services[$key][$level] += $count;
            $services[$key]['lastAt'] = max($services[$key]['lastAt'] ?? 0, (int) $row->last_at);
            if ($level === 'error') {
                $services[$key]['lastErrorAt'] = (int) $row->last_at;
            }

            $machines[$row->machine_id] ??= ['id' => (int) $row->machine_id, 'name' => $names[$row->machine_id] ?? null, 'lines' => 0, 'error' => 0, 'warn' => 0];
            $machines[$row->machine_id]['lines'] += $count;
            if ($level === 'error' || $level === 'warn') {
                $machines[$row->machine_id][$level] += $count;
            }
        }

        $ranked = collect($services)
            ->sortBy([['error', 'desc'], ['warn', 'desc'], ['lines', 'desc'], ['service', 'asc']])
            ->values()
            ->map(fn (array $s) => [
                ...$s,
                'lastAt' => self::iso($s['lastAt']),
                'lastErrorAt' => self::iso($s['lastErrorAt']),
            ]);

        return [
            'window' => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String()],
            'filters' => $filters->describe(),
            'total' => array_sum($byLevel),
            'byLevel' => $byLevel,
            'machines' => collect($machines)->sortByDesc('lines')->values()->all(),
            'services' => $ranked->take($top)->all(),
            'servicesTotal' => $ranked->count(),
            'timeline' => $this->timeline($filters, $since, $until),
            'retentionDays' => $context->workspace->logRetentionDays(),
            'url' => $filters->url($context),
        ];
    }

    /**
     * Lines, warnings and errors per bucket across the window, empty
     * buckets included, so a spike stands out.
     *
     * @return array{bucketMinutes: int, buckets: list<array{from: string, lines: int, warn: int, error: int}>}
     */
    private function timeline(LogQuery $filters, CarbonImmutable $since, CarbonImmutable $until): array
    {
        $minutes = $since->diffInMinutes($until);
        $bucketMinutes = match (true) {
            $minutes <= 120 => 5,
            $minutes <= 720 => 30,
            $minutes <= 2880 => 60,
            $minutes <= 10080 => 360,
            // Long windows: whole days, no more than 400 buckets.
            default => 1440 * max(1, (int) ceil($minutes / 1440 / 400)),
        };
        $size = $bucketMinutes * 60_000;

        $rows = $filters->query()
            ->selectRaw('logged_at - (logged_at % ?) as bucket, level, count(*) as n', [$size])
            ->groupBy('bucket', 'level')
            ->toBase()
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $bucket = (int) $row->bucket;
            $counts[$bucket] ??= ['lines' => 0, 'warn' => 0, 'error' => 0];
            $counts[$bucket]['lines'] += (int) $row->n;
            if ($row->level === 'error' || $row->level === 'warn') {
                $counts[$bucket][$row->level] += (int) $row->n;
            }
        }

        $buckets = [];
        $start = $since->getTimestampMs() - ($since->getTimestampMs() % $size);
        for ($at = $start; $at <= $until->getTimestampMs(); $at += $size) {
            $buckets[] = ['from' => self::iso($at), ...($counts[$at] ?? ['lines' => 0, 'warn' => 0, 'error' => 0])];
        }

        return ['bucketMinutes' => $bucketMinutes, 'buckets' => $buckets];
    }

    private static function iso(?int $ms): ?string
    {
        return $ms === null || $ms === 0 ? null : CarbonImmutable::createFromTimestampMsUTC($ms)->format('Y-m-d\TH:i:s.v\Z');
    }
}
