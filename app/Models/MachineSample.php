<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One minute of a machine's usage: the average and peak of the readings the
 * daemon sent in that minute, for the machine charts.
 *
 * @property int $id
 * @property int $machine_id
 * @property int $ts
 * @property int $samples
 * @property float|null $cpu
 * @property float|null $cpu_max
 * @property int|null $mem_used
 * @property int|null $mem_max
 * @property int|null $disk_percent
 */
class MachineSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** How long history is kept. */
    public const KEEP_DAYS = 30;

    /** Most points a chart gets; minutes are combined into buckets. */
    public const MAX_POINTS = 120;

    /**
     * Fold readings into their minutes.
     *
     * @param  list<array{ts: int, cpu: float|null, mem: int|null, disk: int|null}>  $readings
     */
    public static function record(Machine $machine, array $readings): void
    {
        $oldest = now()->subDays(self::KEEP_DAYS)->getTimestamp();

        foreach ($readings as $reading) {
            if ($reading['ts'] < $oldest) {
                continue;
            }

            $sample = self::query()->firstOrNew([
                'machine_id' => $machine->id,
                'ts' => intdiv($reading['ts'], 60) * 60,
            ]);
            $count = $sample->samples;
            $mean = fn (int|float|null $current, int|float|null $next) => match (true) {
                $next === null => $current,
                $current === null => $next,
                default => ($current * $count + $next) / ($count + 1),
            };
            $peak = fn (int|float|null $current, int|float|null $next) => $current === null || $next === null
                ? $current ?? $next
                : max($current, $next);

            $sample->fill([
                'samples' => min(65535, $count + 1),
                'cpu' => $mean($sample->cpu, $reading['cpu']),
                'cpu_max' => $peak($sample->cpu_max, $reading['cpu']),
                'mem_used' => ($mem = $mean($sample->mem_used, $reading['mem'])) === null ? null : (int) round($mem),
                'mem_max' => $peak($sample->mem_max, $reading['mem']),
                'disk_percent' => $peak($sample->disk_percent, $reading['disk']),
            ])->save();
        }
    }

    /**
     * The window as at most MAX_POINTS evenly spaced buckets, oldest first.
     * Buckets with no readings (the machine was offline) are null, so the
     * charts show a gap.
     *
     * @return list<array{ts: int, cpu: float|null, cpuMax: float|null, mem: int|null, memMax: int|null, disk: int|null}>
     */
    public static function history(Machine $machine, int $window): array
    {
        $bucket = max(60, (int) ceil($window / self::MAX_POINTS / 60) * 60);
        $now = now()->getTimestamp();
        $first = intdiv($now - $window, $bucket) * $bucket + $bucket;

        $rows = self::query()
            ->where('machine_id', $machine->id)
            ->where('ts', '>=', $first)
            ->selectRaw('ts - (ts % ?) as bucket', [$bucket])
            ->selectRaw('avg(cpu) as cpu, max(cpu_max) as cpu_max, avg(mem_used) as mem_used, max(mem_max) as mem_max, max(disk_percent) as disk_percent')
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (self $row) => (int) $row->getAttribute('bucket'));

        $number = fn (mixed $value, int $precision) => $value === null ? null : round((float) $value, $precision);

        $points = [];
        for ($ts = $first; $ts <= $now; $ts += $bucket) {
            $row = $rows->get($ts);
            $points[] = [
                'ts' => $ts,
                'cpu' => $number($row?->cpu, 2),
                'cpuMax' => $number($row?->cpu_max, 2),
                'mem' => $row?->mem_used === null ? null : (int) $row->mem_used,
                'memMax' => $row?->mem_max === null ? null : (int) $row->mem_max,
                'disk' => $row?->disk_percent === null ? null : (int) $row->disk_percent,
            ];
        }

        return $points;
    }
}
