<?php

use App\Support\Edition;
use App\Support\InstallPing;
use App\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Cache::forever(SchedulerHeartbeat::KEY, now()->timestamp))->everyMinute()->name('scheduler-heartbeat');

Schedule::command('backups:run-due')->hourly()->withoutOverlapping();
Schedule::command('projects:tick')->everyMinute()->withoutOverlapping(5);
Schedule::command('logs:prune')->hourly()->withoutOverlapping();
Schedule::command('logs:flush-buffers')->everyMinute()->withoutOverlapping(5);
Schedule::command('view-as:close-stale')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('monitors:run')->everyThirtySeconds()->withoutOverlapping(5)->runInBackground();
Schedule::command('monitors:run --prune')->dailyAt('03:17')->withoutOverlapping();
Schedule::command('machines:watch')->everyMinute()->withoutOverlapping(5);
Schedule::command('maintenance:tick')->everyMinute()->withoutOverlapping(5);
Schedule::command('updates:check-due')->hourlyAt(23)->withoutOverlapping(30);
Schedule::command('updates:tick')->everyMinute()->withoutOverlapping(5);
Schedule::command('domains:check')->everyMinute()->withoutOverlapping(5);
Schedule::command('email-domains:recheck')->dailyAt('04:19')->withoutOverlapping();
Schedule::command('workflows:tick')->everyThirtySeconds()->withoutOverlapping(5);
Schedule::command('workflows:tick --prune')->dailyAt('03:23')->withoutOverlapping();
Schedule::command('summaries:send')->weeklyOn(1, '07:41')->withoutOverlapping();
Schedule::command('plans:expire-manual')->hourly()->withoutOverlapping();
Schedule::command('plans:end-grace')->hourly()->withoutOverlapping();
Schedule::command('deploy-sources:prune')->hourly()->withoutOverlapping();

if (Edition::selfHosted()) {
    Schedule::command('daemon:sync-releases')->hourlyAt(29)->withoutOverlapping();
    Schedule::command('panel:check-updates')->hourlyAt(41)->withoutOverlapping();
}

if (InstallPing::enabled()) {
    Schedule::command('install:ping')->dailyAt(InstallPing::dailyAt())->withoutOverlapping(30);
}
