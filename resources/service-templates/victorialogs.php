<?php

return [
    'name' => 'VictoriaLogs',
    'category' => 'Databases',
    'description' => 'Log database from the VictoriaMetrics team, light on disk and memory.',
    'icon' => 'victoriametrics',
    'kind' => 'compose',
    'port' => 9428,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Ship logs from Vector, Promtail, Fluent Bit or OpenTelemetry to http://127.0.0.1:PORT. The query UI is at /select/vmui. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'RETENTION', 'label' => 'Keep logs for', 'default' => '30d'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  victorialogs:
    image: victoriametrics/victoria-logs:latest
    restart: unless-stopped
    command:
      - -storageDataPath=/vlogs
      - -retentionPeriod=${RETENTION}
    ports:
      - "127.0.0.1:${PORT}:9428"
      - "${DOCKER_BRIDGE}:${PORT}:9428"
    volumes:
      - data:/vlogs
volumes:
  data:
YAML,
    ],
];
