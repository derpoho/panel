<?php

return [
    'name' => 'ToolJet',
    'category' => 'Self-hosted',
    'description' => 'Low-code builder for internal tools and dashboards, with Postgres.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Open it in the browser to create the admin account. The first start runs migrations and takes a few minutes.',
    'env' => [
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'LOCKBOX_SEED', 'label' => 'Encryption seed', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'PGRST_JWT_SECRET', 'label' => 'PostgREST secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-tooljet-db: &db
  PG_HOST: postgresql
  PG_USER: postgres
  PG_PASS: ${DB_PASSWORD}
  PG_DB: tooljet_production
  TOOLJET_DB: tooljet_db
  TOOLJET_DB_HOST: postgresql
  TOOLJET_DB_USER: postgres
  TOOLJET_DB_PASS: ${DB_PASSWORD}
  PGRST_HOST: postgrest
  PGRST_JWT_SECRET: ${PGRST_JWT_SECRET}
  PGRST_DB_URI: postgres://postgres:${DB_PASSWORD}@postgresql/tooljet_db
services:
  tooljet:
    image: tooljet/tooljet-ce:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    depends_on: [postgresql]
    command: [sh, -c, "export LOCKBOX_MASTER_KEY=$$(printf %s \"$$LOCKBOX_SEED\" | sha256sum | cut -c1-64) && exec npm run start:prod"]
    environment:
      <<: *db
      TOOLJET_HOST: ${PUBLIC_URL}
      LOCKBOX_SEED: ${LOCKBOX_SEED}
      SECRET_KEY_BASE: ${SECRET_KEY_BASE}
      SERVE_CLIENT: "true"
      PORT: "80"
      DEPLOYMENT_PLATFORM: docker
    ports:
      - "127.0.0.1:${PORT}:80"
  postgresql:
    image: postgres:13
    restart: unless-stopped
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  postgrest:
    image: postgrest/postgrest:v12.0.2
    restart: unless-stopped
    depends_on: [postgresql]
    environment:
      <<: *db
      PGRST_SERVER_PORT: "80"
      PGRST_DB_PRE_CONFIG: postgrest.pre_config
volumes:
  db:
YAML,
    ],
];
