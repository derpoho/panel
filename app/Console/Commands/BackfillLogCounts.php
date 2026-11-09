<?php

namespace App\Console\Commands;

use App\Support\Logs\LogCounts;
use App\Support\Logs\LogStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills log_counts from the lines stored before it existed, an hour of
 * lines at a time. Safe to run again: each minute is recounted from
 * log_entries, not added to. The last few minutes are left to ingest.
 * Our database only.
 */
class BackfillLogCounts extends Command
{
    protected $signature = 'logs:backfill-counts';

    protected $description = 'Count the stored log lines per minute for the Logs chart';

    public function handle(): int
    {
        $hour = 3_600_000;
        $ours = LogStore::ours();
        $oldest = DB::connection($ours)->table('log_entries')->min('logged_at');
        if ($oldest === null) {
            $this->info('No lines to count.');

            return self::SUCCESS;
        }

        // Lines still arriving for these minutes are counted as they land.
        $until = now()->subMinutes(2)->getTimestampMs();
        $until -= $until % LogCounts::MINUTE;
        $from = (int) $oldest - ((int) $oldest % LogCounts::MINUTE);

        $bar = $this->output->createProgressBar((int) ceil(max(0, $until - $from) / $hour));
        for ($at = $from; $at < $until; $at += $hour) {
            LogCounts::recount($at, min($at + $hour, $until), $ours);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
