<?php

return [
    'name' => 'pgvector',
    'category' => 'Databases',
    'description' => 'Postgres 17 with the pgvector extension for embeddings and similarity search.',
    'icon' => 'postgresql',
    'kind' => 'compose',
    'port' => 5432,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at postgres://USER:PASSWORD@127.0.0.1:PORT/DATABASE, then run CREATE EXTENSION vector; once per database. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'POSTGRES_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'POSTGRES_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'POSTGRES_DB', 'label' => 'Database', 'default' => 'app'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  postgres:
    image: pgvector/pgvector:pg17
    restart: unless-stopped
    environment:
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_DB: ${POSTGRES_DB}
    ports:
      - "127.0.0.1:${PORT}:5432"
      - "${DOCKER_BRIDGE}:${PORT}:5432"
    volumes:
      - data:/var/lib/postgresql/data
volumes:
  data:
YAML,
    ],
];
