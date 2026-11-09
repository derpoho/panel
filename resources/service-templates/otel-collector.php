<?php

return [
    'name' => 'OpenTelemetry Collector',
    'category' => 'Infrastructure',
    'description' => 'Takes in OpenTelemetry traces, metrics and logs, batches them and sends them on.',
    'icon' => 'opentelemetry',
    'kind' => 'compose',
    'port' => 4318,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Apps send OTLP over HTTP to http://{bridge}:PORT and over gRPC to {bridge} on the gRPC port. It forwards everything over OTLP to the address you set (a backend on this machine is at http://{bridge} on its port) and prints a short summary to its logs. Change config.yaml to add other exporters.',
    'env' => [
        ['key' => 'GRPC_PORT', 'label' => 'OTLP gRPC port', 'default' => '4317'],
        ['key' => 'EXPORT_ENDPOINT', 'label' => 'Send to (OTLP HTTP URL)', 'required' => true, 'hint' => 'Where your backend takes OTLP over HTTP, like Tempo, OpenObserve or another collector.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  otel-collector:
    image: otel/opentelemetry-collector-contrib:latest
    restart: unless-stopped
    command: ["--config=/etc/otelcol-contrib/config.yaml"]
    environment:
      EXPORT_ENDPOINT: ${EXPORT_ENDPOINT}
    ports:
      - "127.0.0.1:${PORT}:4318"
      - "${DOCKER_BRIDGE}:${PORT}:4318"
      - "127.0.0.1:${GRPC_PORT}:4317"
      - "${DOCKER_BRIDGE}:${GRPC_PORT}:4317"
    volumes:
      - ./config.yaml:/etc/otelcol-contrib/config.yaml:ro
YAML,
        'config.yaml' => <<<'YAML'
receivers:
  otlp:
    protocols:
      grpc:
        endpoint: 0.0.0.0:4317
      http:
        endpoint: 0.0.0.0:4318

processors:
  memory_limiter:
    check_interval: 1s
    limit_percentage: 80
    spike_limit_percentage: 20
  batch: {}

exporters:
  debug:
    verbosity: basic
  otlphttp:
    endpoint: ${env:EXPORT_ENDPOINT}

extensions:
  health_check:
    endpoint: 0.0.0.0:13133

service:
  extensions: [health_check]
  pipelines:
    traces:
      receivers: [otlp]
      processors: [memory_limiter, batch]
      exporters: [debug, otlphttp]
    metrics:
      receivers: [otlp]
      processors: [memory_limiter, batch]
      exporters: [debug, otlphttp]
    logs:
      receivers: [otlp]
      processors: [memory_limiter, batch]
      exporters: [debug, otlphttp]
YAML,
    ],
];
