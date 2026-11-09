<?php

return [
    'name' => 'Automatisch',
    'category' => 'Infrastructure',
    'description' => 'Open source Zapier alternative for connecting apps, with Postgres and Redis.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The first login is user@automatisch.io / sample. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'WEBHOOK_SECRET', 'label' => 'Webhook secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-automatisch-env: &automatisch-env
  APP_ENV: production
  WEB_APP_URL: ${APP_URL}
  WEBHOOK_URL: ${APP_URL}
  POSTGRES_HOST: db
  POSTGRES_DATABASE: automatisch
  POSTGRES_USERNAME: automatisch
  POSTGRES_PASSWORD: ${DB_PASSWORD}
  REDIS_HOST: redis
  ENCRYPTION_KEY: ${ENCRYPTION_KEY}
  WEBHOOK_SECRET_KEY: ${WEBHOOK_SECRET}
  APP_SECRET_KEY: ${APP_SECRET}
services:
  main:
    image: automatischio/automatisch:latest
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      <<: *automatisch-env
      PORT: "3000"
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - storage:/automatisch/storage
  worker:
    image: automatischio/automatisch:latest
    restart: unless-stopped
    depends_on: [main]
    environment:
      <<: *automatisch-env
      WORKER: "true"
    volumes:
      - storage:/automatisch/storage
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: automatisch
      POSTGRES_USER: automatisch
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  storage:
  db:
  redis:
YAML,
    ],
];
