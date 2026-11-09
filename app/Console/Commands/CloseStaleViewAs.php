<?php

namespace App\Console\Commands;

use App\Models\ImpersonationSession;
use Illuminate\Console\Command;

class CloseStaleViewAs extends Command
{
    protected $signature = 'view-as:close-stale';

    protected $description = 'End "View as" sessions that went quiet for longer than a sign-in lasts, as of when they were last used';

    public function handle(): int
    {
        $closed = 0;
        ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('last_seen_at', '<', now()->subMinutes((int) config('session.lifetime')))
            ->with(['admin', 'user'])
            ->each(function (ImpersonationSession $session) use (&$closed): void {
                $session->finish('expired');
                $closed++;
            });

        $this->info("Closed {$closed} View as ".($closed === 1 ? 'session' : 'sessions').'.');

        return self::SUCCESS;
    }
}
