<?php

return [
    'name' => 'Umami',
    'category' => 'Self-hosted',
    'description' => 'Privacy-friendly website analytics, with Postgres.',
    'icon' => 'umami',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/heartbeat',
    'notes' => 'The first login is admin / umami. Change it straight away.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  umami:
    image: ghcr.io/umami-software/umami:postgresql-latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgresql://umami:${DB_PASSWORD}@db:5432/umami
      APP_SECRET: ${APP_SECRET}
    ports:
      - "127.0.0.1:${PORT}:3000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: umami
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
