<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;

/**
 * A route name as a page title, for View as history:
 * workspace.machines.services.show reads "Machines › Services".
 */
final class PageName
{
    public static function of(string $route): string
    {
        $parts = array_filter(
            explode('.', (string) preg_replace('/^workspace\./', '', $route)),
            fn (string $part) => ! in_array($part, ['index', 'show'], true),
        );

        return implode(' › ', array_map(fn (string $part) => match ($part) {
            'api' => 'API',
            'mcp' => 'MCP',
            default => Str::ucfirst(str_replace(['-', '_'], ' ', $part)),
        }, $parts)) ?: 'Home';
    }
}
