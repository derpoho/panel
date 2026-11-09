<?php

return [
    'name' => 'Plausible',
    'category' => 'Self-hosted',
    'description' => 'Lightweight, cookie-free web analytics, with Postgres and ClickHouse.',
    'icon' => 'plausible',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Register the first account in the browser. Add a domain before adding the script to your sites.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DISABLE_REGISTRATION', 'label' => 'Registration', 'default' => 'invite_only', 'hint' => 'true, false or invite_only. The first account can always register.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  plausible:
    image: ghcr.io/plausible/community-edition:v2.1
    restart: unless-stopped
    depends_on: [db, events]
    command: sh -c "/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"
    environment:
      BASE_URL: ${APP_URL}
      SECRET_KEY_BASE: ${SECRET_KEY_BASE}
      DISABLE_REGISTRATION: ${DISABLE_REGISTRATION}
      DATABASE_URL: postgres://plausible:${DB_PASSWORD}@db:5432/plausible
      CLICKHOUSE_DATABASE_URL: http://events:8123/plausible
    ports:
      - "127.0.0.1:${PORT}:8000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: plausible
      POSTGRES_USER: plausible
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  events:
    image: clickhouse/clickhouse-server:24.3-alpine
    restart: unless-stopped
    environment:
      CLICKHOUSE_SKIP_USER_SETUP: "1"
    ulimits:
      nofile:
        soft: 262144
        hard: 262144
    volumes:
      - events:/var/lib/clickhouse
volumes:
  db:
  events:
YAML,
    ],
];
