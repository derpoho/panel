<?php

namespace App\Console\Commands;

use App\Support\Edition;
use App\Support\InstallPing;
use Illuminate\Console\Command;

/**
 * The anonymous daily install ping (App\Support\InstallPing): --show prints
 * exactly what it sends, and without it the ping goes now. Scheduled once a
 * day on self-hosted installs unless SERVEROS_TELEMETRY=false
 * (routes/console.php). It never fails: a ping that doesn't arrive is only
 * a warning.
 */
class SendInstallPing extends Command
{
    protected $signature = 'install:ping {--show : Print exactly what would be sent, without sending it}';

    protected $description = 'Send the anonymous install ping now, or show what it sends';

    public function handle(): int
    {
        if ($this->option('show')) {
            $this->line('Sent to '.InstallPing::url().', as JSON:');
            $this->line((string) json_encode(InstallPing::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line(match (true) {
                ! Edition::selfHosted() => 'Only self-hosted installs send it, so this one never does.',
                ! InstallPing::enabled() => 'It\'s off (SERVEROS_TELEMETRY=false), so this install never sends it.',
                default => 'Sent once a day at '.InstallPing::dailyAt().'. Turn it off with SERVEROS_TELEMETRY=false.',
            });

            return self::SUCCESS;
        }

        if (! InstallPing::enabled()) {
            $this->info(Edition::selfHosted()
                ? 'The install ping is off (SERVEROS_TELEMETRY=false), so nothing was sent.'
                : 'Only self-hosted installs send the install ping, so nothing was sent.');

            return self::SUCCESS;
        }

        $error = InstallPing::send();
        if ($error !== null) {
            $this->warn($error);
        } else {
            $this->info('Sent.');
        }

        return self::SUCCESS;
    }
}
