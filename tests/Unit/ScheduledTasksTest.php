<?php

use App\Support\ScheduledTasks;

test('cron schedules read as plain words, or not at all', function (string $schedule, ?string $words) {
    expect(ScheduledTasks::describeCron($schedule))->toBe($words);
})->with([
    ['@daily', 'every day'],
    ['@reboot', 'when the machine starts'],
    ['* * * * *', 'every minute'],
    ['*/5 * * * *', 'every 5 minutes'],
    ['17 * * * *', 'every hour at :17'],
    ['0 */6 * * *', 'every 6 hours at :00'],
    ['0 3 * * *', 'every day at 03:00'],
    ['30 0,12 * * *', 'every day at 00:30 and 12:30'],
    ['0 4 * * 1', 'every Monday at 04:00'],
    ['0 9 * * 1-5', 'every weekday at 09:00'],
    ['0 2 * * 0,6', 'every Sunday and Saturday at 02:00'],
    ['15 1 1 * *', 'every month on the 1st at 01:15'],
    ['0 0 22 * *', 'every month on the 22nd at 00:00'],
    ['0 0 1 1 *', null],
    ['*/10 9-17 * * *', null],
    ['not a schedule', null],
]);

test('timer rules read as plain words, or not at all', function (string $schedule, ?string $words) {
    expect(ScheduledTasks::describeTimer($schedule))->toBe($words);
})->with([
    ['*-*-* 00:00:00', 'every day at 00:00'],
    ['*-*-* 06,18:00:00', 'every day at 06:00 and 18:00'],
    ['Mon *-*-* 00:00:00', 'every Monday at 00:00'],
    ['Mon..Fri *-*-* 09:30:00', 'every weekday at 09:30'],
    ['daily', 'every day'],
    ['OnUnitActiveSec=1d', 'every day'],
    ['OnBootSec=15min; OnUnitActiveSec=12h', '15 minutes after the machine starts and every 12 hours'],
    ['*-*-* *:00/15:00', null],
]);
