<?php

return [
    'name' => 'Temporal',
    'category' => 'Infrastructure',
    'description' => 'Durable workflow engine, with Postgres and the Temporal web UI.',
    'icon' => 'temporal',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The main port is the web UI, which has no login of its own. Workers and clients connect to the frontend at {bridge} on the gRPC port. The default namespace is created on first start.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'GRPC_PORT', 'label' => 'gRPC port', 'default' => '7233'],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  temporal:
    image: temporalio/auto-setup:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DB: postgres12
      DB_PORT: 5432
      POSTGRES_USER: temporal
      POSTGRES_PWD: ${DB_PASSWORD}
      POSTGRES_SEEDS: db
    ports:
      - "127.0.0.1:${GRPC_PORT}:7233"
      - "${DOCKER_BRIDGE}:${GRPC_PORT}:7233"
  ui:
    image: temporalio/ui:latest
    restart: unless-stopped
    depends_on: [temporal]
    environment:
      TEMPORAL_ADDRESS: temporal:7233
      TEMPORAL_CORS_ORIGINS: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8080"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_USER: temporal
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
