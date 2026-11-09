<?php

namespace App\Support;

use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The cron jobs and systemd timers a machine's last scan found, as the
 * machine page lists them: what runs, when in plain words, as whom, when
 * it last ran and runs next (timers say), and the service it belongs to
 * where that's clear.
 */
final class ScheduledTasks
{
    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private const CALENDAR_DAYS = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

    /**
     * @return list<array{command: string, schedule: string, when: string|null, user: string|null, kind: string, source: string, description: string|null, nextRun: string|null, lastRun: string|null, service: array{key: string, name: string}|null, ours: bool}>
     */
    public static function for(Machine $machine): array
    {
        $services = array_values(array_filter(AppReleases::visible($machine), fn (array $s) => is_string($s['key'] ?? null)));
        $rows = [];

        foreach ($machine->inventory['scheduled'] ?? [] as $task) {
            if (! is_array($task) || ! is_string($task['command'] ?? null) || ! is_string($task['schedule'] ?? null)) {
                continue;
            }
            $timer = ($task['source'] ?? '') === 'systemd-timer';
            $schedule = trim($task['schedule']);
            // Older daemons sent a timer's unit as its schedule, not when it runs.
            $known = ! ($timer && str_ends_with($schedule, '.timer'));
            $service = self::serviceFor($machine, $task, $services);

            $rows[] = [
                'command' => $task['command'],
                'schedule' => $schedule,
                'when' => $known ? ($timer ? self::describeTimer($schedule) : self::describeCron($schedule)) : null,
                'user' => is_string($task['user'] ?? null) ? $task['user'] : null,
                'kind' => $timer ? 'timer' : 'cron',
                'source' => $timer ? (string) ($task['unit'] ?? $schedule) : (string) ($task['source'] ?? ''),
                'description' => is_string($task['description'] ?? null) ? $task['description'] : null,
                'nextRun' => self::time($task['next_run'] ?? null),
                'lastRun' => self::time($task['last_run'] ?? null),
                'service' => $service === null ? null : ['key' => (string) $service['key'], 'name' => ServiceLabels::display($machine, $service)],
                'ours' => str_contains($task['command'], 'serverosd') || str_contains((string) ($task['source'] ?? ''), 'serveros'),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['kind'], $a['command']] <=> [$b['kind'], $b['command']]);

        return $rows;
    }

    /**
     * A cron schedule in plain words, or null when it's one we don't put
     * into words (the raw schedule shows instead).
     */
    public static function describeCron(string $schedule): ?string
    {
        $named = [
            '@reboot' => 'when the machine starts', '@yearly' => 'every year', '@annually' => 'every year',
            '@monthly' => 'every month', '@weekly' => 'every week', '@daily' => 'every day', '@midnight' => 'every day at 00:00',
            '@hourly' => 'every hour',
        ];
        if (isset($named[strtolower($schedule)])) {
            return $named[strtolower($schedule)];
        }

        $fields = preg_split('/\s+/', trim($schedule)) ?: [];
        if (count($fields) !== 5) {
            return null;
        }
        [$minute, $hour, $day, $month, $weekday] = $fields;
        if ($month !== '*') {
            return null;
        }

        if ($hour === '*' && $day === '*' && $weekday === '*') {
            if ($minute === '*') {
                return 'every minute';
            }
            if (preg_match('/^\*\/(\d+)$/', $minute, $m)) {
                return (int) $m[1] === 1 ? 'every minute' : "every {$m[1]} minutes";
            }
            if (ctype_digit($minute)) {
                return 'every hour at :'.str_pad($minute, 2, '0', STR_PAD_LEFT);
            }

            return null;
        }

        if (! ctype_digit($minute)) {
            return null;
        }
        if (preg_match('/^\*\/(\d+)$/', $hour, $m) && $day === '*' && $weekday === '*') {
            return "every {$m[1]} hours at :".str_pad($minute, 2, '0', STR_PAD_LEFT);
        }

        $times = self::times($hour, $minute);
        if ($times === null) {
            return null;
        }
        if ($day === '*' && $weekday === '*') {
            return "every day at {$times}";
        }
        if ($day === '*') {
            $days = self::weekdays($weekday);

            return $days === null ? null : "every {$days} at {$times}";
        }
        if ($weekday === '*' && ctype_digit($day)) {
            return 'every month on the '.self::ordinal((int) $day)." at {$times}";
        }

        return null;
    }

    /**
     * A timer's OnCalendar or interval rules ("; " between several) in
     * plain words, or null.
     */
    public static function describeTimer(string $schedule): ?string
    {
        $parts = [];
        foreach (array_filter(array_map('trim', explode(';', $schedule))) as $rule) {
            $words = self::describeTimerRule($rule);
            if ($words === null) {
                return null;
            }
            $parts[] = $words;
        }

        return $parts === [] ? null : implode(' and ', array_unique($parts));
    }

    private static function describeTimerRule(string $rule): ?string
    {
        if (preg_match('/^On(Boot|Startup|UnitActive|UnitInactive|Active)Sec=(.+)$/', $rule, $m)) {
            $span = self::span($m[2]);
            if ($span === null) {
                return null;
            }

            return match ($m[1]) {
                'Boot', 'Startup' => "{$span} after the machine starts",
                default => "every {$span}",
            };
        }

        $named = ['minutely' => 'every minute', 'hourly' => 'every hour', 'daily' => 'every day', 'weekly' => 'every week', 'monthly' => 'every month', 'yearly' => 'every year', 'annually' => 'every year'];
        if (isset($named[strtolower($rule)])) {
            return $named[strtolower($rule)];
        }

        // [Weekday] *-*-* HH:MM[:SS]
        if (! preg_match('/^(?:([A-Za-z]{3}(?:[.,][A-Za-z]{3})*(?:\.\.[A-Za-z]{3})?)\s+)?\*-\*-\*\s+([\d,]+):(\d{2})(?::\d{2})?$/', $rule, $m)) {
            return null;
        }
        $times = self::times($m[2], $m[3]);
        if ($times === null) {
            return null;
        }
        if ($m[1] === '') {
            return "every day at {$times}";
        }

        $days = str_contains($m[1], '..')
            ? self::weekdays(implode('-', array_map(fn ($d) => self::CALENDAR_DAYS[ucfirst(strtolower($d))] ?? 'x', explode('..', $m[1]))))
            : self::weekdays(implode(',', array_map(fn ($d) => self::CALENDAR_DAYS[ucfirst(strtolower($d))] ?? 'x', preg_split('/[.,]/', $m[1]) ?: [])));

        return $days === null ? null : "every {$days} at {$times}";
    }

    /** "6,18" and "0" as "06:00 and 18:00". */
    private static function times(string $hours, string $minute): ?string
    {
        $list = explode(',', $hours);
        foreach ($list as $hour) {
            if (! ctype_digit($hour) || (int) $hour > 23) {
                return null;
            }
        }
        if (! ctype_digit($minute) || (int) $minute > 59) {
            return null;
        }
        $times = array_map(fn ($h) => sprintf('%02d:%02d', (int) $h, (int) $minute), $list);

        return count($times) === 1 ? $times[0] : implode(', ', array_slice($times, 0, -1)).' and '.end($times);
    }

    /** Cron weekdays (1, 1-5, 0,6) as "Monday", "weekday", "Saturday and Sunday". */
    private static function weekdays(string $field): ?string
    {
        if (in_array($field, ['1-5', 'Mon-Fri'], true)) {
            return 'weekday';
        }
        $days = [];
        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d)-(\d)$/', $part, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $d) {
                    $days[] = $d % 7;
                }
            } elseif (ctype_digit($part) && (int) $part <= 7) {
                $days[] = (int) $part % 7;
            } else {
                return null;
            }
        }
        $names = array_map(fn ($d) => self::WEEKDAYS[$d], array_values(array_unique($days)));

        return count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    /** systemd spans (1d, 12h, 15min, 1w 2d) in words. */
    private static function span(string $span): ?string
    {
        $words = [];
        foreach (preg_split('/\s+/', trim($span)) ?: [] as $part) {
            if (! preg_match('/^(\d+)(us|ms|s|sec|m|min|h|hr|d|w|month|y)?$/', $part, $m)) {
                return null;
            }
            $n = (int) $m[1];
            $unit = match ($m[2] ?? 's') {
                'min', 'm' => 'minute', 'h', 'hr' => 'hour', 'd' => 'day', 'w' => 'week', 'month' => 'month', 'y' => 'year',
                default => 'second',
            };
            $words[] = $n === 1 ? $unit : "{$n} {$unit}s";
        }

        return $words === [] ? null : implode(' ', $words);
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The service a task belongs to, where it's clear: a timer's unit, a
     * container named in the command, or a service's folder the command
     * works in.
     *
     * @param  array<string, mixed>  $task
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>|null
     */
    private static function serviceFor(Machine $machine, array $task, array $services): ?array
    {
        $command = (string) $task['command'];

        if (($task['source'] ?? '') === 'systemd-timer') {
            foreach ($services as $service) {
                if ($service['key'] === "systemd:{$command}") {
                    return $service;
                }
            }

            return null;
        }

        foreach ($services as $service) {
            $name = (string) ($service['name'] ?? '');
            if (str_starts_with((string) $service['key'], 'docker:') && strlen($name) >= 3
                && preg_match('/(^|[\s"\'=])'.preg_quote($name, '/').'($|[\s"\'])/', $command) === 1) {
                return $service;
            }
        }
        foreach ($services as $service) {
            $dir = rtrim((string) ($service['working_dir'] ?? ''), '/');
            if (strlen($dir) > 1 && preg_match('/(^|[\s"\'=])'.preg_quote($dir, '/').'($|[\s"\'\/])/', $command) === 1) {
                return $service;
            }
        }

        return null;
    }
}
