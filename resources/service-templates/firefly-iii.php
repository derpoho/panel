<?php

return [
    'name' => 'Firefly III',
    'category' => 'Self-hosted',
    'description' => 'Personal finance manager for budgets, accounts and recurring bills.',
    'icon' => 'fireflyiii',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Open it in the browser and register: the first account becomes the owner. A small cron container runs the daily jobs for recurring transactions.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'CRON_TOKEN', 'label' => 'Cron token', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  app:
    image: fireflyiii/core:latest
    restart: unless-stopped
    stop_signal: SIGINT
    depends_on: [db]
    environment:
      APP_ENV: production
      APP_KEY: ${APP_KEY}
      APP_URL: ${APP_URL}
      TZ: ${TIMEZONE}
      TRUSTED_PROXIES: "**"
      STATIC_CRON_TOKEN: ${CRON_TOKEN}
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: firefly
      DB_USERNAME: firefly
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - upload:/var/www/html/storage/upload
  cron:
    image: alpine:3
    restart: unless-stopped
    init: true
    depends_on: [app]
    environment:
      CRON_TOKEN: ${CRON_TOKEN}
    command: ["sh", "-c", "echo \"0 3 * * * wget -qO- http://app:8080/api/v1/cron/$$CRON_TOKEN; echo\" | crontab - && exec crond -f -L /dev/stdout"]
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: firefly
      POSTGRES_USER: firefly
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  upload:
  db:
YAML,
    ],
];
