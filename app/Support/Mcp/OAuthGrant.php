<?php

namespace App\Support\Mcp;

use App\Models\Workspace;

/**
 * What an approval grants: the scopes, the resource the token is for (its
 * audience's MCP server URL, checked again when the code is exchanged),
 * and the workspace, for a workspace's token.
 */
final readonly class OAuthGrant
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public array $scopes,
        public string $resource,
        public ?Workspace $workspace = null,
    ) {}
}
