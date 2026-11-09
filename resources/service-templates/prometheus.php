<?php

return [
    'name' => 'Prometheus',
    'category' => 'Infrastructure',
    'description' => 'Metrics collection and alerting, keeping 15 days of data.',
    'icon' => 'prometheus',
    'kind' => 'compose',
    'port' => 9090,
    'web' => true,
    'port_editable' => true,
    'health' => '/-/healthy',
    'notes' => 'Starts out scraping itself; point Grafana at it as a data source. It has no login, so think twice before adding a domain.',
    'env' => [
        ['key' => 'RETENTION', 'label' => 'Keep data for', 'default' => '15d'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  prometheus:
    image: prom/prometheus:latest
    restart: unless-stopped
    command:
      - --config.file=/etc/prometheus/prometheus.yml
      - --storage.tsdb.path=/prometheus
      - --storage.tsdb.retention.time=${RETENTION}
    ports:
      - "127.0.0.1:${PORT}:9090"
    volumes:
      - data:/prometheus
volumes:
  data:
YAML,
    ],
];
