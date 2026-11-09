<?php

return [
    'name' => 'Grafana Tempo',
    'category' => 'Infrastructure',
    'description' => 'Trace store from Grafana that takes OpenTelemetry traces and keeps them on disk.',
    'icon' => 'grafana',
    'kind' => 'compose',
    'port' => 3200,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Add it to Grafana as a Tempo data source at http://{bridge}:PORT. Apps send OTLP traces to {bridge} on the OTLP gRPC or HTTP port. It has no login, so it only listens locally and on the Docker bridge.',
    'env' => [
        ['key' => 'OTLP_GRPC_PORT', 'label' => 'OTLP gRPC port', 'default' => '4317'],
        ['key' => 'OTLP_HTTP_PORT', 'label' => 'OTLP HTTP port', 'default' => '4318'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tempo:
    image: grafana/tempo:latest
    restart: unless-stopped
    user: "0"
    command: ["-config.file=/etc/tempo.yaml"]
    ports:
      - "127.0.0.1:${PORT}:3200"
      - "${DOCKER_BRIDGE}:${PORT}:3200"
      - "127.0.0.1:${OTLP_GRPC_PORT}:4317"
      - "${DOCKER_BRIDGE}:${OTLP_GRPC_PORT}:4317"
      - "127.0.0.1:${OTLP_HTTP_PORT}:4318"
      - "${DOCKER_BRIDGE}:${OTLP_HTTP_PORT}:4318"
    volumes:
      - ./tempo.yaml:/etc/tempo.yaml:ro
      - data:/var/tempo
volumes:
  data:
YAML,
        'tempo.yaml' => <<<'YAML'
stream_over_http_enabled: true
server:
  http_listen_port: 3200
  log_level: info

distributor:
  receivers:
    otlp:
      protocols:
        grpc:
          endpoint: 0.0.0.0:4317
        http:
          endpoint: 0.0.0.0:4318

compactor:
  compaction:
    block_retention: 336h

storage:
  trace:
    backend: local
    wal:
      path: /var/tempo/wal
    local:
      path: /var/tempo/blocks
YAML,
    ],
];
