<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpOAuthClient;
use App\Support\Mcp\OAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dynamic client registration (RFC 7591): an AI app introduces itself and
 * gets a client id. Apps are public clients (no secret); they prove
 * themselves with PKCE, and a person approves each one on a consent page.
 */
class OAuthRegistrationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();
        $uris = $data['redirect_uris'] ?? null;

        if (! is_array($uris) || $uris === [] || count($uris) > 10 || ! array_is_list($uris)) {
            return $this->fail('invalid_redirect_uri', 'Send redirect_uris: one to ten URLs.');
        }
        foreach ($uris as $uri) {
            if (! OAuth::redirectAllowed($uri)) {
                return $this->fail('invalid_redirect_uri', 'Redirect URIs must be HTTPS, HTTP to localhost, or an app’s own scheme, without a fragment.');
            }
        }
        $grants = $data['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (! is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            return $this->fail('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.');
        }
        $responses = $data['response_types'] ?? ['code'];
        if (! is_array($responses) || array_diff($responses, ['code']) !== []) {
            return $this->fail('invalid_client_metadata', 'Only the code response type is supported.');
        }

        $name = trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', '', (string) ($data['client_name'] ?? '')) ?? '');
        $clientUri = is_string($data['client_uri'] ?? null) && str_starts_with($data['client_uri'], 'https://') ? Str::limit($data['client_uri'], 290, '') : null;

        $client = McpOAuthClient::query()->create([
            'client_id' => 'mcp_'.Str::random(32),
            'name' => $name === '' ? 'An AI app' : Str::limit($name, 110, ''),
            'redirect_uris' => array_values(array_unique($uris)),
            'client_uri' => $clientUri,
            'software_id' => is_string($data['software_id'] ?? null) ? Str::limit($data['software_id'], 110, '') : null,
            'created_ip' => $request->ip(),
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201)->header('Cache-Control', 'no-store');
    }

    private function fail(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400);
    }
}
