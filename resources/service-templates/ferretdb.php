<?php

return [
    'name' => 'FerretDB',
    'category' => 'Databases',
    'description' => 'MongoDB-compatible database, stored in Postgres with DocumentDB.',
    'icon' => 'ferretdb',
    'kind' => 'compose',
    'port' => 27017,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with any MongoDB driver at mongodb://USER:PASSWORD@127.0.0.1:PORT/. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'DB_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'DB_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ferretdb:
    image: ghcr.io/ferretdb/ferretdb:2
    restart: unless-stopped
    depends_on: [postgres]
    environment:
      FERRETDB_POSTGRESQL_URL: postgres://${DB_USER}:${DB_PASSWORD}@postgres:5432/postgres
    ports:
      - "127.0.0.1:${PORT}:27017"
      - "${DOCKER_BRIDGE}:${PORT}:27017"
  postgres:
    image: ghcr.io/ferretdb/postgres-documentdb:17
    restart: unless-stopped
    environment:
      POSTGRES_USER: ${DB_USER}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: postgres
    volumes:
      - data:/var/lib/postgresql/data
volumes:
  data:
YAML,
    ],
];
