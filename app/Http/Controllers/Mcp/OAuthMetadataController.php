<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Mcp\OAuth;
use Illuminate\Http\JsonResponse;

/**
 * Discovery: how an MCP client finds out where to sign in.
 */
class OAuthMetadataController extends Controller
{
    /** On a workspace's own domain: which server issues its tokens. */
    public function resource(Workspace $workspace): JsonResponse
    {
        return response()->json(OAuth::resourceMetadata($workspace))->header('Cache-Control', 'public, max-age=300');
    }

    /** On the main domain: the authorization server itself. */
    public function server(): JsonResponse
    {
        return response()->json(OAuth::serverMetadata())->header('Cache-Control', 'public, max-age=300');
    }
}
