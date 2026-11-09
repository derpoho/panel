<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Show hidden services in the panel's lists again, as Unhide. Nothing on the machine changes.
 */
final class UnhideService extends Tool
{
    public function name(): string
    {
        return 'unhide_service';
    }

    public function title(): string
    {
        return 'Show hidden services again';
    }

    public function description(): string
    {
        return 'Shows services someone hid from ServerOS’s lists again (list_services with hidden: true lists them), with their alerts and Needs attention items. Nothing on the machine changes. Pass service, or services for several on one machine.';
    }

    public function inputSchema(): array
    {
        return ServiceVisibility::schema();
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return ServiceVisibility::apply($context, $this->validate($arguments, ServiceVisibility::rules()), false);
    }
}
