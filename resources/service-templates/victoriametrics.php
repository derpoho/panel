<?php

return [
    'name' => 'VictoriaMetrics',
    'category' => 'Databases',
    'description' => 'Fast time series database that works as a Prometheus backend.',
    'icon' => 'victoriametrics',
    'kind' => 'compose',
    'port' => 8428,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Point Prometheus remote_write or Grafana at http://127.0.0.1:PORT. The query UI is at /vmui. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'RETENTION', 'label' => 'Keep data for', 'default' => '3', 'hint' => 'In months, or with a suffix like 30d or 1y.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  victoriametrics:
    image: victoriametrics/victoria-metrics:latest
    restart: unless-stopped
    command:
      - -storageDataPath=/storage
      - -retentionPeriod=${RETENTION}
    ports:
      - "127.0.0.1:${PORT}:8428"
      - "${DOCKER_BRIDGE}:${PORT}:8428"
    volumes:
      - data:/storage
volumes:
  data:
YAML,
    ],
];
