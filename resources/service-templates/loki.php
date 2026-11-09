<?php

return [
    'name' => 'Grafana Loki',
    'category' => 'Infrastructure',
    'description' => 'Log store from Grafana that indexes labels, not the whole log line.',
    'icon' => 'grafana',
    'kind' => 'compose',
    'port' => 3100,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Add it to Grafana as a Loki data source at http://{bridge}:PORT, and point Alloy or Promtail at http://{bridge}:PORT/loki/api/v1/push. It has no login, so it only listens locally and on the Docker bridge.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  loki:
    image: grafana/loki:latest
    restart: unless-stopped
    command: -config.file=/etc/loki/local-config.yaml
    ports:
      - "127.0.0.1:${PORT}:3100"
      - "${DOCKER_BRIDGE}:${PORT}:3100"
    volumes:
      - data:/loki
volumes:
  data:
YAML,
    ],
];
