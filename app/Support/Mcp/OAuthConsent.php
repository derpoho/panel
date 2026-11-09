<?php

namespace App\Support\Mcp;

/**
 * An audience's consent page for one authorization request: what to keep
 * in the session until the person decides (its scopes and anything else
 * approve() needs), and the props the page gets after the request's nonce
 * and the client.
 */
final readonly class OAuthConsent
{
    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $props
     */
    public function __construct(
        public array $pending,
        public array $props,
    ) {}
}
