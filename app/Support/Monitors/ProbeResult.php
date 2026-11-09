<?php

namespace App\Support\Monitors;

/**
 * What one check found.
 */
final class ProbeResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $responseMs = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $error = null,
    ) {}

    public static function fail(string $error, ?int $responseMs = null, ?int $statusCode = null): self
    {
        return new self(false, $responseMs, $statusCode, mb_substr($error, 0, 300));
    }
}
