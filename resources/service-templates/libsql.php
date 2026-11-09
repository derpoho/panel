<?php

return [
    'name' => 'libSQL Server',
    'category' => 'Databases',
    'description' => 'The open source SQLite fork behind Turso, served over HTTP.',
    'icon' => 'turso',
    'kind' => 'compose',
    'port' => 8080,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Connect a libSQL client at http://127.0.0.1:PORT. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  libsql:
    image: ghcr.io/tursodatabase/libsql-server:latest
    restart: unless-stopped
    environment:
      SQLD_NODE: primary
      SQLD_HTTP_LISTEN_ADDR: 0.0.0.0:8080
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "${DOCKER_BRIDGE}:${PORT}:8080"
    volumes:
      - data:/var/lib/sqld
volumes:
  data:
YAML,
    ],
];
