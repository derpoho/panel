<?php

namespace App\Mcp\Tools\Workspace;

use Closure;

/**
 * Validation rules the workspace tools share.
 */
final class Rules
{
    /**
     * A reference to a machine or a person: an id or a name, not a list
     * or an object.
     */
    public static function reference(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail("The {$attribute} must be an id or a name.");
        }
    }

    /**
     * The arguments actually given. Some clients send the optional ones
     * they don't use as null or "", which would otherwise slip past the
     * validator and count as a filter (a blank severity, limit 0).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function given(array $arguments): array
    {
        return array_filter($arguments, fn (mixed $value) => $value !== null && ! (is_string($value) && trim($value) === ''));
    }
}
