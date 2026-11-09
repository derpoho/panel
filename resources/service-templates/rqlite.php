<?php

return [
    'name' => 'rqlite',
    'category' => 'Databases',
    'description' => 'Lightweight SQLite-based database you talk to over HTTP.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 4001,
    'web' => false,
    'port_editable' => true,
    'health' => '/readyz',
    'notes' => 'Send SQL to http://127.0.0.1:PORT/db/execute and /db/query, or use the rqlite shell. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rqlite:
    image: rqlite/rqlite:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:4001"
      - "${DOCKER_BRIDGE}:${PORT}:4001"
    volumes:
      - data:/rqlite/file
volumes:
  data:
YAML,
    ],
];
