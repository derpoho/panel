<?php

return [
    'name' => 'Quickwit',
    'category' => 'Infrastructure',
    'description' => 'Search engine for logs and traces, with a small web UI.',
    'icon' => 'search',
    'kind' => 'compose',
    'port' => 7280,
    'web' => true,
    'port_editable' => true,
    'health' => '/health/livez',
    'notes' => 'The UI is at /ui and has no login, so put something in front before giving it a public domain. Apps on this machine send data to http://{bridge}:PORT/api/v1. OTLP over gRPC listens on the gRPC port.',
    'env' => [
        ['key' => 'GRPC_PORT', 'label' => 'gRPC port', 'default' => '7281'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  quickwit:
    image: quickwit/quickwit:latest
    restart: unless-stopped
    command: run
    environment:
      QW_LISTEN_ADDRESS: 0.0.0.0
      QW_ENABLE_OTLP_ENDPOINT: "true"
    ports:
      - "127.0.0.1:${PORT}:7280"
      - "${DOCKER_BRIDGE}:${PORT}:7280"
      - "127.0.0.1:${GRPC_PORT}:7281"
      - "${DOCKER_BRIDGE}:${GRPC_PORT}:7281"
    volumes:
      - data:/quickwit/qwdata
volumes:
  data:
YAML,
    ],
];
