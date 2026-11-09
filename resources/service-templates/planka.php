<?php

return [
    'name' => 'Planka',
    'category' => 'Self-hosted',
    'description' => 'Kanban boards for your projects with real-time updates, with Postgres.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 1337,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the admin email and password you set here.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  planka:
    image: ghcr.io/plankanban/planka:latest
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      BASE_URL: ${APP_URL}
      DATABASE_URL: postgresql://planka:${DB_PASSWORD}@db/planka
      SECRET_KEY: ${SECRET_KEY}
      TRUST_PROXY: "true"
      DEFAULT_ADMIN_EMAIL: ${ADMIN_EMAIL}
      DEFAULT_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      DEFAULT_ADMIN_NAME: Admin
      DEFAULT_ADMIN_USERNAME: admin
    ports:
      - "127.0.0.1:${PORT}:1337"
    volumes:
      - data:/app/data
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: planka
      POSTGRES_USER: planka
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U planka -d planka"]
      interval: 10s
      timeout: 5s
      retries: 5
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
