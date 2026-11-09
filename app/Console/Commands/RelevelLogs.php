<?php

namespace App\Console\Commands;

use App\Models\LogEntry;
use App\Support\Logs\LogCounts;
use App\Support\Logs\LogLevel;
use App\Support\Logs\LogStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Journald lines stored as info because journald said priority 6, when
 * the line itself said otherwise (dockerd's level=error). Reads each
 * again and moves it, and its chart count, to the level it shows. Our
 * database only: lines in a workspace's own store are theirs.
 */
class RelevelLogs extends Command
{
    protected $signature = 'logs:relevel';

    protected $description = 'Read the level again on journald lines stored as info, now that priority 6 no longer outranks the line';

    public function handle(): int
    {
        $moved = 0;
        $ours = LogStore::ours();

        LogEntry::on($ours)->where('stream', 'journal')->where('level', 'info')
            ->select(['id', 'machine_id', 'service', 'logged_at', 'level', 'message'])
            ->chunkById(2000, function ($lines) use (&$moved, $ours): void {
                $changed = $lines->map(fn (LogEntry $line) => [$line, LogLevel::detect($line->message)])
                    ->filter(fn (array $pair) => $pair[1] !== 'info');
                if ($changed->isEmpty()) {
                    return;
                }

                DB::connection($ours)->transaction(function () use ($changed, $ours): void {
                    foreach ($changed->groupBy(fn (array $pair) => $pair[1]) as $level => $pairs) {
                        LogEntry::on($ours)->whereIn('id', $pairs->map(fn (array $pair) => $pair[0]->id)->all())->update(['level' => $level]);
                    }
                    $row = fn (LogEntry $line, string $level) => ['machine_id' => $line->machine_id, 'service' => $line->service, 'logged_at' => $line->logged_at, 'level' => $level];
                    LogCounts::subtract(array_values($changed->map(fn (array $pair) => $row($pair[0], 'info'))->all()), $ours);
                    LogCounts::add(array_values($changed->map(fn (array $pair) => $row($pair[0], $pair[1]))->all()), $ours);
                });
                $moved += $changed->count();
            });

        $this->info("Moved {$moved} lines to the level they show.");

        return self::SUCCESS;
    }
}
