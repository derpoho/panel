<?php

return [
    'name' => 'Vikunja',
    'category' => 'Self-hosted',
    'description' => 'To-do lists and projects with list, kanban, gantt and table views, with Postgres.',
    'icon' => 'vikunja',
    'kind' => 'compose',
    'port' => 3456,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/info',
    'notes' => 'Open it in the browser and register your account. Anyone can sign up until you add VIKUNJA_SERVICE_ENABLEREGISTRATION=false as extra environment.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  vikunja:
    image: vikunja/vikunja:latest
    restart: unless-stopped
    # Root so it can write to the files volume.
    user: "0:0"
    depends_on:
      db:
        condition: service_healthy
    environment:
      VIKUNJA_SERVICE_PUBLICURL: ${APP_URL}
      VIKUNJA_SERVICE_SECRET: ${APP_SECRET}
      VIKUNJA_DATABASE_TYPE: postgres
      VIKUNJA_DATABASE_HOST: db
      VIKUNJA_DATABASE_DATABASE: vikunja
      VIKUNJA_DATABASE_USER: vikunja
      VIKUNJA_DATABASE_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3456"
    volumes:
      - files:/app/vikunja/files
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: vikunja
      POSTGRES_USER: vikunja
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -h localhost -U vikunja"]
      interval: 10s
      start_period: 15s
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  files:
  db:
YAML,
    ],
];
