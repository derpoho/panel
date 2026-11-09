<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One per-service usage reading from the daemon, for the service charts.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $service
 * @property int $ts
 * @property float|null $cpu
 * @property int|null $mem_used
 * @property int|null $net_rx
 * @property int|null $net_tx
 * @property int|null $pids
 */
class ServiceSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** How long history is kept. */
    public const KEEP_SECONDS = 86400;

    /**
     * Fewest seconds between kept readings: the charts never bucket finer
     * than this, so readings in between only took up room.
     */
    public const EVERY_SECONDS = 15;

    /** Readings older than this are thinned to one a minute per service. */
    public const FULL_SECONDS = 3600;

    /** Where thinning got to, so each run only does the hours since. */
    private const THINNED_UNTIL = 'service-samples:thinned-until';

    /**
     * Whether a reading from this machine should be kept now: at most one
     * batch per EVERY_SECONDS.
     */
    public static function due(Machine $machine): bool
    {
        return Cache::add('service-samples:'.$machine->id, true, self::EVERY_SECONDS);
    }

    /**
     * Drop readings past the history window, for every machine (ones that
     * stopped reporting too), and keep one a minute per service once
     * they're older than FULL_SECONDS, which is all the 6 and 24 hour
     * charts use. Thinned an hour at a time, picking up where the last run
     * stopped. Returns how many went.
     */
    public static function prune(): int
    {
        $now = now()->getTimestamp();
        $deleted = self::query()->where('ts', '<', $now - self::KEEP_SECONDS)->delete();

        $until = intdiv($now - self::FULL_SECONDS, 60) * 60;
        $from = max(intdiv($now - self::KEEP_SECONDS, 60) * 60, (int) Cache::get(self::THINNED_UNTIL, 0));
        for ($start = $from; $start < $until; $start += 3600) {
            $end = min($start + 3600, $until);
            $extra = self::query()
                ->selectRaw('id, row_number() over (partition by machine_id, service, ts / 60 order by ts, id) as nth')
                ->where('ts', '>=', $start)
                ->where('ts', '<', $end);

            $deleted += self::query()
                ->whereIn('id', fn ($query) => $query->fromSub($extra, 'readings')->where('nth', '>', 1)->select('id'))
                ->delete();
            Cache::forever(self::THINNED_UNTIL, $end);
        }

        return $deleted;
    }
}
