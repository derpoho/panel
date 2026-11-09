<?php

return [
    'name' => 'Twenty',
    'category' => 'Self-hosted',
    'description' => 'A modern open source CRM for contacts, companies and deals, with Postgres and Redis.',
    'icon' => 'twenty',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Open it in the browser to create your account and workspace. The first start sets up the database and takes a few minutes.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:44}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  server:
    image: twentycrm/twenty:latest
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started
    environment:
      NODE_PORT: 3000
      SERVER_URL: ${APP_URL}
      ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      PG_DATABASE_URL: postgres://twenty:${DB_PASSWORD}@db:5432/twenty
      REDIS_URL: redis://redis:6379
      STORAGE_TYPE: local
    healthcheck:
      test: curl --fail http://localhost:3000/healthz
      interval: 5s
      timeout: 5s
      retries: 30
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - storage:/app/packages/twenty-server/.local-storage
  worker:
    image: twentycrm/twenty:latest
    restart: unless-stopped
    command: ["yarn", "worker:prod"]
    depends_on:
      server:
        condition: service_healthy
    environment:
      SERVER_URL: ${APP_URL}
      ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      PG_DATABASE_URL: postgres://twenty:${DB_PASSWORD}@db:5432/twenty
      REDIS_URL: redis://redis:6379
      STORAGE_TYPE: local
      DISABLE_DB_MIGRATIONS: "true"
      DISABLE_CRON_JOBS_REGISTRATION: "true"
    volumes:
      - storage:/app/packages/twenty-server/.local-storage
  db:
    image: postgres:16
    restart: unless-stopped
    environment:
      POSTGRES_DB: twenty
      POSTGRES_USER: twenty
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: pg_isready -U twenty -h localhost -d twenty
      interval: 5s
      timeout: 5s
      retries: 10
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--maxmemory-policy", "noeviction"]
volumes:
  storage:
  db:
YAML,
    ],
];
