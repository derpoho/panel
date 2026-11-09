<?php

return [
    'name' => 'GreptimeDB',
    'category' => 'Databases',
    'description' => 'Database for metrics, logs and traces, queried with SQL or PromQL.',
    'icon' => 'greptimedb',
    'kind' => 'compose',
    'port' => 4000,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'The HTTP API and dashboard are at http://127.0.0.1:PORT/dashboard. SQL clients connect with the MySQL or Postgres protocol on the ports below. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'MYSQL_PORT', 'label' => 'MySQL protocol port', 'default' => '4002'],
        ['key' => 'POSTGRES_PORT', 'label' => 'Postgres protocol port', 'default' => '4003'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  greptimedb:
    image: greptime/greptimedb:latest
    restart: unless-stopped
    command:
      - standalone
      - start
      - --http-addr=0.0.0.0:4000
      - --rpc-bind-addr=0.0.0.0:4001
      - --mysql-addr=0.0.0.0:4002
      - --postgres-addr=0.0.0.0:4003
      - --data-home=/greptimedb_data
    ports:
      - "127.0.0.1:${PORT}:4000"
      - "${DOCKER_BRIDGE}:${PORT}:4000"
      - "127.0.0.1:${MYSQL_PORT}:4002"
      - "${DOCKER_BRIDGE}:${MYSQL_PORT}:4002"
      - "127.0.0.1:${POSTGRES_PORT}:4003"
      - "${DOCKER_BRIDGE}:${POSTGRES_PORT}:4003"
    volumes:
      - data:/greptimedb_data
volumes:
  data:
YAML,
    ],
];
