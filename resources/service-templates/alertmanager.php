<?php

return [
    'name' => 'Alertmanager',
    'category' => 'Infrastructure',
    'description' => 'Groups, silences and routes the alerts Prometheus sends it.',
    'icon' => 'prometheus',
    'kind' => 'compose',
    'port' => 9093,
    'web' => true,
    'port_editable' => true,
    'health' => '/-/healthy',
    'notes' => 'Point Prometheus at {bridge}:PORT. It starts with the stock config, which sends alerts nowhere useful until you add your own receivers. The UI has no login, so put something in front before giving it a public domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  alertmanager:
    image: prom/alertmanager:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9093"
      - "${DOCKER_BRIDGE}:${PORT}:9093"
    volumes:
      - data:/alertmanager
volumes:
  data:
YAML,
    ],
];
