<?php

return [
    'name' => 'PgBouncer',
    'category' => 'Infrastructure',
    'description' => 'Lightweight connection pooler that sits in front of Postgres.',
    'icon' => 'postgresql',
    'kind' => 'compose',
    'port' => 6432,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Point it at your database, then connect apps to it instead: 127.0.0.1:PORT on this machine, {bridge}:PORT from apps deployed on it, with the same user and password. For Postgres on this machine, use {bridge} as the host in the database URL. Pool mode is transaction.',
    'env' => [
        ['key' => 'DATABASE_URL', 'label' => 'Database URL', 'default' => '', 'required' => true, 'hint' => 'postgres://user:password@host:5432/dbname'],
        ['key' => 'POOL_MODE', 'label' => 'Pool mode', 'default' => 'transaction'],
        ['key' => 'MAX_CLIENT_CONN', 'label' => 'Max client connections', 'default' => '500'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pgbouncer:
    image: edoburu/pgbouncer:latest
    restart: unless-stopped
    environment:
      DATABASE_URL: ${DATABASE_URL}
      AUTH_TYPE: scram-sha-256
      POOL_MODE: ${POOL_MODE}
      MAX_CLIENT_CONN: ${MAX_CLIENT_CONN}
      LISTEN_PORT: 6432
    ports:
      - "127.0.0.1:${PORT}:6432"
      - "${DOCKER_BRIDGE}:${PORT}:6432"
YAML,
    ],
];
