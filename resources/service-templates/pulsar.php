<?php

return [
    'name' => 'Apache Pulsar',
    'category' => 'Infrastructure',
    'description' => 'Messaging and streaming, run as a single standalone node.',
    'icon' => 'apachepulsar',
    'kind' => 'compose',
    'port' => 6650,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect to pulsar://127.0.0.1:PORT on this machine, or pulsar://{bridge}:PORT from apps you deploy on it. The admin REST API is on the HTTP port, same addresses. No login, so it only listens locally and on the Docker bridge.',
    'env' => [
        ['key' => 'HTTP_PORT', 'label' => 'Admin HTTP port', 'default' => '8080'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pulsar:
    image: apachepulsar/pulsar:latest
    restart: unless-stopped
    command: bin/pulsar standalone
    ports:
      - "127.0.0.1:${PORT}:6650"
      - "${DOCKER_BRIDGE}:${PORT}:6650"
      - "127.0.0.1:${HTTP_PORT}:8080"
      - "${DOCKER_BRIDGE}:${HTTP_PORT}:8080"
    volumes:
      - data:/pulsar/data
volumes:
  data:
YAML,
    ],
];
