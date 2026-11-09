<?php

namespace App\Api;

use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Support\Docs\DocsUrl;

/**
 * The API described as OpenAPI 3.1, built from the routes and the tools'
 * own JSON Schemas, so it's never out of date: a route's path parameters,
 * its query string (GET) or JSON body (anything else), the scope its key
 * needs, and the answer it gives.
 */
final class OpenApi
{
    /** @return array<string, mixed> */
    public static function document(ToolRegistry $tools): array
    {
        $paths = [];
        $seen = [];
        foreach (ApiRoutes::all() as $route) {
            $tool = $tools->find($route['tool']);
            if ($tool === null) {
                continue;
            }
            $schema = (array) json_decode((string) json_encode($tool->inputSchema()), true);
            $properties = (array) ($schema['properties'] ?? []);
            $required = (array) ($schema['required'] ?? []);
            $inPath = ApiRoutes::parameters($route['path']);
            $method = strtolower($route['method']);

            $operationId = $route['tool'];
            if (isset($seen[$operationId])) {
                $operationId .= '_'.str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $route['path']);
            }
            $seen[$operationId] = true;

            $parameters = array_map(fn (string $name) => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'description' => $properties[$name]['description'] ?? null,
                'schema' => self::loose($properties[$name] ?? ['type' => 'string']),
            ], $inPath);

            $rest = array_diff_key($properties, array_flip($inPath));
            $restRequired = array_values(array_diff($required, $inPath));
            $operation = [
                'operationId' => $operationId,
                'summary' => $tool->title(),
                'description' => $tool->description(),
                'tags' => [$route['tag']],
                'x-scope' => $tool->scope(),
                'x-destructive' => $tool->destructive(),
                'x-tool' => $tool->name(),
                'security' => [['apiKey' => [$tool->scope()]]],
            ];

            if ($method === 'get') {
                foreach ($rest as $name => $property) {
                    $parameters[] = array_filter([
                        'name' => $name,
                        'in' => 'query',
                        'required' => in_array($name, $restRequired, true),
                        'description' => $property['description'] ?? null,
                        'schema' => self::loose($property),
                        'style' => ($property['type'] ?? null) === 'array' ? 'form' : null,
                        'explode' => ($property['type'] ?? null) === 'array' ? false : null,
                    ], fn ($v) => $v !== null);
                }
            } elseif ($rest !== []) {
                $operation['requestBody'] = [
                    'required' => $restRequired !== [],
                    'content' => ['application/json' => ['schema' => array_filter([
                        'type' => 'object',
                        'properties' => $rest,
                        'required' => $restRequired === [] ? null : $restRequired,
                        'additionalProperties' => false,
                    ], fn ($v) => $v !== null)]],
                ];
            }

            $operation['parameters'] = array_map(fn (array $p) => array_filter($p, fn ($v) => $v !== null), $parameters);
            $operation['responses'] = [
                '200' => ['description' => 'Done. What the tool answered is under data.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Data']]]],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/Unprocessable'],
                '429' => ['$ref' => '#/components/responses/RateLimited'],
            ];

            $paths['/'.$route['path']][$method] = $operation;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'ServerOS API',
                'version' => '1.0.0',
                'description' => 'Everything in a ServerOS workspace: machines, services, deploys, jobs, monitors, status pages, workflows, backups, logs and the team. Authenticate with a workspace API key from Settings › API as a bearer token. Every route runs the same tool an AI app connected over MCP would, with the same checks.',
                'contact' => ['name' => 'ServerOS support', 'email' => (string) config('mail.support.address'), 'url' => DocsUrl::page('guides', 'api')],
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'tags' => array_map(fn (string $name, string $description) => ['name' => $name, 'description' => $description], array_keys(ApiRoutes::TAGS), ApiRoutes::TAGS),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'apiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'A workspace API key (sos_…). Its access decides what it may do: '.implode(', ', Scopes::ALL).'.',
                    ],
                ],
                'schemas' => [
                    'Data' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['description' => 'What the tool answered.']]],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => ['error' => [
                            'type' => 'object',
                            'required' => ['type', 'message'],
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['unauthenticated', 'forbidden', 'plan_required', 'not_found', 'invalid_json', 'invalid_arguments', 'unprocessable', 'rate_limited', 'server_error']],
                                'message' => ['type' => 'string', 'description' => 'What went wrong, in plain words.'],
                            ],
                        ]],
                    ],
                ],
                'responses' => [
                    'Unauthenticated' => self::errorResponse('No API key, or one that’s invalid or revoked.'),
                    'Forbidden' => self::errorResponse('The key’s access or its owner’s role doesn’t allow this.'),
                    'NotFound' => self::errorResponse('It doesn’t exist in this workspace.'),
                    'Unprocessable' => self::errorResponse('The arguments are wrong, or it can’t be done right now; the message says which.'),
                    'RateLimited' => self::errorResponse('Too many requests this minute. Retry-After says when to try again.'),
                ],
            ],
        ];
    }

    /**
     * Path and query values arrive as text; ids that are "a machine by id
     * or name" are strings in the tools' schemas, so they stay as they are.
     *
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    private static function loose(array $property): array
    {
        unset($property['description']);

        return $property === [] ? ['type' => 'string'] : $property;
    }

    /** @return array<string, mixed> */
    private static function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }
}
