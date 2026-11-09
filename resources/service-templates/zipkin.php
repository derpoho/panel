<?php

return [
    'name' => 'Zipkin',
    'category' => 'Infrastructure',
    'description' => 'Distributed tracing server with a UI to follow requests across services.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 9411,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Apps send spans to http://{bridge}:PORT/api/v2/spans. Traces are kept in memory, so they are gone after a restart. The UI has no login, so put something in front before giving it a public domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  zipkin:
    image: openzipkin/zipkin:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9411"
      - "${DOCKER_BRIDGE}:${PORT}:9411"
YAML,
    ],
];
