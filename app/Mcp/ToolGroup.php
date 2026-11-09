<?php

namespace App\Mcp;

/**
 * A set of related tools (machines, services, deploys...). Groups are
 * listed in config/mcp.php.
 */
interface ToolGroup
{
    /** @return list<class-string<Tool>> */
    public static function tools(): array;
}
