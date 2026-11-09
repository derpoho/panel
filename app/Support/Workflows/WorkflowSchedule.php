<?php

namespace App\Support\Workflows;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a scheduled workflow runs next: every hour at a minute, every day at
 * a time, or every week on a day at a time. Times are UTC.
 */
final class WorkflowSchedule
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public static function next(array $rules, ?CarbonInterface $after = null): ?CarbonImmutable
    {
        $after = CarbonImmutable::instance($after ?? now())->setSecond(0)->setMicrosecond(0);
        [$hour, $minute] = self::time((string) ($rules['at'] ?? '03:00'));

        return match ($rules['every'] ?? null) {
            'hour' => ($at = $after->setMinute($minute))->gt($after) ? $at : $at->addHour(),
            'day' => ($at = $after->setTime($hour, $minute))->gt($after) ? $at : $at->addDay(),
            'week' => (function () use ($after, $hour, $minute, $rules) {
                $weekday = max(1, min(7, (int) ($rules['weekday'] ?? 1)));
                $at = $after->setTime($hour, $minute)->addDays(($weekday - $after->dayOfWeekIso + 7) % 7);

                return $at->gt($after) ? $at : $at->addWeek();
            })(),
            default => null,
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function time(string $at): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $at, $m) !== 1) {
            return [3, 0];
        }

        return [min(23, (int) $m[1]), min(59, (int) $m[2])];
    }
}
