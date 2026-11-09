<?php

return [
    'name' => 'Swagger UI',
    'category' => 'Infrastructure',
    'description' => 'Browse and try out an OpenAPI spec in an interactive page.',
    'icon' => 'swagger',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Point the spec URL at your own openapi.json or yaml. The API has to allow CORS from this page for "Try it out" to work.',
    'env' => [
        ['key' => 'SPEC_URL', 'label' => 'OpenAPI spec URL', 'default' => 'https://petstore3.swagger.io/api/v3/openapi.json'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  swagger-ui:
    image: swaggerapi/swagger-ui:latest
    restart: unless-stopped
    environment:
      URL: ${SPEC_URL}
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
