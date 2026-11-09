<?php

return [
    'name' => 'TDengine',
    'category' => 'Databases',
    'description' => 'Time-series database for IoT and metrics, with a REST and WebSocket API.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 6041,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect over REST or WebSocket at http://127.0.0.1:PORT. The default login is root / taosdata, so change it with ALTER USER root PASS. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tdengine:
    image: tdengine/tdengine:latest
    restart: unless-stopped
    hostname: tdengine
    environment:
      TAOS_FQDN: tdengine
    ports:
      - "127.0.0.1:${PORT}:6041"
      - "${DOCKER_BRIDGE}:${PORT}:6041"
    volumes:
      - data:/var/lib/taos
      - logs:/var/log/taos
volumes:
  data:
  logs:
YAML,
    ],
];
