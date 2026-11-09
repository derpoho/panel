<?php

return [
    'name' => 'Jaeger',
    'category' => 'Infrastructure',
    'description' => 'Distributed tracing UI. OTLP on localhost:4317 (gRPC) and 4318 (HTTP).',
    'icon' => 'jaeger',
    'kind' => 'compose',
    'port' => 16686,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Keeps traces in memory, so they are gone after a restart. It has no login, so think twice before adding a domain.',
    'env' => [
        ['key' => 'MAX_TRACES', 'label' => 'Traces kept in memory', 'default' => '100000'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  jaeger:
    image: jaegertracing/all-in-one:latest
    restart: unless-stopped
    environment:
      COLLECTOR_OTLP_ENABLED: "true"
      MEMORY_MAX_TRACES: ${MAX_TRACES}
    ports:
      - "127.0.0.1:${PORT}:16686"
      - "127.0.0.1:4317:4317"
      - "127.0.0.1:4318:4318"
YAML,
    ],
];
