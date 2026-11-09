<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Log\GetLogStats;
use App\Mcp\Tools\Log\SearchLogs;

/**
 * Service logs: searching and following the lines services write, and
 * counting them to find the noisy or failing ones.
 */
final class LogTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            SearchLogs::class,
            GetLogStats::class,
        ];
    }
}
