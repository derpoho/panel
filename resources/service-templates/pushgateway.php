<?php

return [
    'name' => 'Prometheus Pushgateway',
    'category' => 'Infrastructure',
    'description' => 'Lets batch jobs push metrics for Prometheus to scrape later.',
    'icon' => 'prometheus',
    'kind' => 'compose',
    'port' => 9091,
    'web' => true,
    'port_editable' => true,
    'health' => '/-/healthy',
    'notes' => 'Jobs push to http://{bridge}:PORT/metrics/job/NAME and Prometheus scrapes {bridge}:PORT. Pushed metrics are kept in memory, so they reset when it restarts. The UI has no login, so put something in front before giving it a public domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pushgateway:
    image: prom/pushgateway:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9091"
      - "${DOCKER_BRIDGE}:${PORT}:9091"
YAML,
    ],
];
