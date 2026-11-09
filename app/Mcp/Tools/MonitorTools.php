<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Monitor\CheckMonitorNow;
use App\Mcp\Tools\Monitor\CreateMonitor;
use App\Mcp\Tools\Monitor\DeleteMonitor;
use App\Mcp\Tools\Monitor\GetAlertSettings;
use App\Mcp\Tools\Monitor\GetMonitor;
use App\Mcp\Tools\Monitor\ListMonitors;
use App\Mcp\Tools\Monitor\MuteMonitor;
use App\Mcp\Tools\Monitor\PauseMonitor;
use App\Mcp\Tools\Monitor\ResumeMonitor;
use App\Mcp\Tools\Monitor\SendTestAlert;
use App\Mcp\Tools\Monitor\UnmuteMonitor;
use App\Mcp\Tools\Monitor\UpdateAlertSettings;
use App\Mcp\Tools\Monitor\UpdateMonitor;

/**
 * Uptime monitors: what's checked and how it's doing, adding and changing
 * them, checking, pausing and muting, and where alerts go.
 */
final class MonitorTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListMonitors::class,
            GetMonitor::class,
            CreateMonitor::class,
            UpdateMonitor::class,
            CheckMonitorNow::class,
            PauseMonitor::class,
            ResumeMonitor::class,
            MuteMonitor::class,
            UnmuteMonitor::class,
            DeleteMonitor::class,
            GetAlertSettings::class,
            UpdateAlertSettings::class,
            SendTestAlert::class,
        ];
    }
}
