<?php

namespace App\Console\Commands;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Machines\PackageUpdates;
use Illuminate\Console\Command;

/**
 * Checks each online machine for OS updates about once a day, so the
 * Updates page is never far behind. Runs hourly and takes a limited
 * number each time, longest unchecked first, so a big workspace's
 * checks are spread out rather than sent in one go.
 */
class CheckDueUpdates extends Command
{
    protected $signature = 'updates:check-due {--limit=200 : The most machines to check in one run}';

    protected $description = 'Ask online machines what OS updates are waiting, once a day each';

    /** How old a check can get before the machine is due another. */
    public const int DUE_AFTER_HOURS = 20;

    /** How long before a machine asked recently is asked again. */
    public const int RETRY_AFTER_HOURS = 6;

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $due = Machine::query()
            ->whereNotNull('uid')
            ->whereNull('disconnected_at')
            ->where('last_seen_at', '>=', now()->subSeconds(60))
            ->where(fn ($q) => $q->whereNull('updates_checked_at')->orWhere('updates_checked_at', '<', now()->subHours(self::DUE_AFTER_HOURS)))
            ->orderByRaw('case when updates_checked_at is null then 0 else 1 end')
            ->orderBy('updates_checked_at')
            ->orderBy('id')
            // Room for those passed over as busy.
            ->limit($limit * 2)
            ->with('workspace.owner')
            ->get();

        // Machines working on their packages now are left for next time;
        // so are those asked lately whose check didn't come back (an old
        // daemon, a broken package manager), rather than asked every hour.
        $busy = DaemonJob::query()
            ->whereIn('machine_id', $due->pluck('id'))
            ->whereIn('kind', PackageUpdates::KINDS)
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subHours(self::RETRY_AFTER_HOURS))
                ->orWhere(fn ($q) => $q->whereIn('state', ['queued', 'running'])->where('updated_at', '>=', now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES))))
            ->pluck('machine_id')
            ->flip();

        $queued = 0;
        foreach ($due as $machine) {
            if ($queued >= $limit) {
                break;
            }
            if ($busy->has($machine->id)) {
                continue;
            }
            if (PackageUpdates::check($machine) !== null) {
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->info("Asked {$queued} machine(s) for their updates.");
        }

        return self::SUCCESS;
    }
}
