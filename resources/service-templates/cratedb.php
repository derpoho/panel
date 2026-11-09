<?php

return [
    'name' => 'CrateDB',
    'category' => 'Databases',
    'description' => 'Distributed SQL database for search and analytics, on a single node.',
    'icon' => 'cratedb',
    'kind' => 'compose',
    'port' => 4200,
    'web' => false,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The HTTP API and admin UI are at http://127.0.0.1:PORT. Postgres clients connect as crate on the Postgres port below. There is no password by default, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'PG_PORT', 'label' => 'Postgres protocol port', 'default' => '5433'],
        ['key' => 'CRATE_HEAP_SIZE', 'label' => 'Heap size', 'default' => '1g'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  crate:
    image: crate:latest
    restart: unless-stopped
    command: ["crate", "-Cdiscovery.type=single-node"]
    environment:
      CRATE_HEAP_SIZE: ${CRATE_HEAP_SIZE}
    ports:
      - "127.0.0.1:${PORT}:4200"
      - "${DOCKER_BRIDGE}:${PORT}:4200"
      - "127.0.0.1:${PG_PORT}:5432"
      - "${DOCKER_BRIDGE}:${PG_PORT}:5432"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
