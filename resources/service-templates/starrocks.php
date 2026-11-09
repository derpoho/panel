<?php

return [
    'name' => 'StarRocks',
    'category' => 'Databases',
    'description' => 'Fast analytics database you query with MySQL clients, all in one container.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 9030,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect any MySQL client at 127.0.0.1:PORT as root with no password, then set one with SET PASSWORD. Needs at least 4 GB of memory and takes a minute to start. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  starrocks:
    image: starrocks/allin1-ubuntu:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9030"
      - "${DOCKER_BRIDGE}:${PORT}:9030"
    volumes:
      - fe-meta:/data/deploy/starrocks/fe/meta
      - be-storage:/data/deploy/starrocks/be/storage
volumes:
  fe-meta:
  be-storage:
YAML,
    ],
];
