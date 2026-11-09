<?php

namespace App\Support;

/**
 * The cache key the scheduler writes every minute (routes/console.php), so
 * staff tools can tell whether cron is running schedule:run.
 */
final class SchedulerHeartbeat
{
    public const string KEY = 'system:scheduler-heartbeat';
}
