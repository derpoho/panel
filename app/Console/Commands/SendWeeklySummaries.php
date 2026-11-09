<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Notifications\WeeklySummaryMail;
use App\Support\Health\WeeklySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendWeeklySummaries extends Command
{
    protected $signature = 'summaries:send';

    protected $description = 'Email each workspace’s week to the people who want it';

    public function handle(WeeklySummary $summaries): int
    {
        $sent = 0;

        Workspace::query()
            ->where(fn ($q) => $q->whereNull('summary_sent_at')->orWhere('summary_sent_at', '<', now()->subDays(6)))
            ->chunkById(50, function ($workspaces) use ($summaries, &$sent) {
                foreach ($workspaces as $workspace) {
                    // Marked first: a failure part-way never sends twice.
                    $workspace->forceFill(['summary_sent_at' => now()])->saveQuietly();
                    try {
                        $summary = $summaries->build($workspace);
                        if ($summary === null) {
                            continue;
                        }
                        $recipients = $workspace->members()->wherePivot('weekly_summary', true)->get();
                        if ($recipients->isNotEmpty()) {
                            Notification::send($recipients, new WeeklySummaryMail($workspace->name, $summary, 'https://'.$workspace->host(), $workspace->id));
                            $sent += $recipients->count();
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Sent {$sent} summaries.");

        return self::SUCCESS;
    }
}
