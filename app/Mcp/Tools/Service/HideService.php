<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Hide services from the panel's lists, as Hide in the services list. Nothing on the machine changes.
 */
final class HideService extends Tool
{
    public function name(): string
    {
        return 'hide_service';
    }

    public function title(): string
    {
        return 'Hide services from the lists';
    }

    public function description(): string
    {
        return 'Hides services from ServerOS’s lists (the services lists, Home’s count, status pages and Needs attention), and stops alerts about them, without touching the machine: they keep running, or stay stopped, as they are. For clearing noise like old stopped containers or system units nobody manages. Pass service, or services for several on one machine. list_services with hidden: true shows what’s hidden; unhide_service brings them back. Ask the person first if it’s not what they asked for.';
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
        return ServiceVisibility::apply($context, $this->validate($arguments, ServiceVisibility::rules()), true);
    }
}
