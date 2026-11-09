<?php

return [
    'name' => 'Teable',
    'category' => 'Self-hosted',
    'description' => 'No-code database with a spreadsheet feel, built on Postgres.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Open it in the browser and sign up; the first account becomes the admin.',
    'env' => [
        ['key' => 'PUBLIC_ORIGIN', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  teable:
    image: ghcr.io/teableio/teable:latest
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      PUBLIC_ORIGIN: ${PUBLIC_ORIGIN}
      SECRET_KEY: ${SECRET_KEY}
      BACKEND_JWT_SECRET: ${SECRET_KEY}
      BACKEND_SESSION_SECRET: ${SECRET_KEY}
      PRISMA_DATABASE_URL: postgresql://teable:${DB_PASSWORD}@db:5432/teable
      BACKEND_CACHE_PROVIDER: redis
      BACKEND_CACHE_REDIS_URI: redis://redis:6379/0
      NEXT_ENV_IMAGES_ALL_REMOTE: "true"
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - assets:/app/.assets
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: teable
      POSTGRES_USER: teable
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  assets:
  db:
  redis:
YAML,
    ],
];
