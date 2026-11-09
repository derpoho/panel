<?php

namespace App\Mcp;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Every tool, from the groups config/mcp.php lists, and which of them a
 * given connection may use: its scopes and the person's role decide.
 */
final class ToolRegistry
{
    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    /** @return array<string, Tool> by name */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [];
        foreach ((array) config('mcp.groups', []) as $group) {
            foreach ($group::tools() as $class) {
                /** @var Tool $tool */
                $tool = $this->container->make($class);
                if (isset($tools[$tool->name()])) {
                    throw new LogicException("Two MCP tools are called {$tool->name()}.");
                }
                $tools[$tool->name()] = $tool;
            }
        }

        return $this->tools = $tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, Tool> the tools this connection may use */
    public function for(McpContext $context): array
    {
        return array_filter($this->all(), fn (Tool $tool) => $this->denial($tool, $context) === null);
    }

    /**
     * Why this connection can't use the tool, or null if it can.
     */
    public function denial(Tool $tool, McpContext $context): ?string
    {
        if (! $context->allows($tool->scope())) {
            return match ($tool->scope()) {
                Scopes::WRITE => 'This connection is read-only. To let it make changes, reconnect and allow “Make changes”, or use an API key that allows them.',
                Scopes::COMMANDS => 'This connection can’t run commands. Reconnect and allow “Run commands” (it needs two-factor authentication).',
                default => 'This connection can’t use that tool.',
            };
        }
        if (($permission = $tool->permission()) !== null && ! $context->can($permission)) {
            return "Your role in {$context->workspace->name} doesn’t allow that ({$permission->label()}).";
        }

        return null;
    }
}
