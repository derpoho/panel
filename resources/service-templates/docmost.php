<?php

return [
    'name' => 'Docmost',
    'category' => 'Self-hosted',
    'description' => 'Collaborative wiki and docs for your team, with Postgres and Redis.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Open it in the browser to create the first workspace and admin account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  docmost:
    image: docmost/docmost:latest
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      APP_URL: ${APP_URL}
      APP_SECRET: ${APP_SECRET}
      DATABASE_URL: postgresql://docmost:${DB_PASSWORD}@db:5432/docmost?schema=public
      REDIS_URL: redis://redis:6379
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/app/data/storage
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: docmost
      POSTGRES_USER: docmost
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  data:
  db:
  redis:
YAML,
    ],
];
