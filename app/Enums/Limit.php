<?php

namespace App\Enums;

/**
 * A number that bounds what a workspace can do. Which value applies comes
 * from App\Support\Entitlements\Entitlements; null means no limit.
 */
enum Limit: string
{
    /** Machines connected at once. */
    case Machines = 'machines';

    /** AI tool calls a month through the workspace's MCP server, before packs. */
    case McpCalls = 'mcp_calls';

    /** Days of service logs kept. */
    case LogRetentionDays = 'log_retention_days';

    /** Log lines kept a day across the workspace's machines. */
    case LogLinesPerDay = 'log_lines_per_day';

    /** Days of workspace activity kept. */
    case ActivityRetentionDays = 'activity_retention_days';

    /** Uptime monitors. */
    case Monitors = 'monitors';

    /** The shortest monitor check interval, in seconds. */
    case MinMonitorInterval = 'min_monitor_interval';

    /** People who can subscribe to email updates from each status page. */
    case StatusSubscribers = 'status_subscribers';

    /** REST API requests a minute, per key. */
    case ApiRequestsPerMinute = 'api_requests_per_minute';
}
