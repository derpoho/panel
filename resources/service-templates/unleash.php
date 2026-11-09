<?php

return [
    'name' => 'Unleash',
    'category' => 'Infrastructure',
    'description' => 'Feature flags and gradual rollouts, with Postgres.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 4242,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'The first login is admin / unleash4all. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  unleash:
    image: unleashorg/unleash-server:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgres://unleash:${DB_PASSWORD}@db:5432/unleash
      DATABASE_SSL: "false"
      UNLEASH_URL: ${APP_URL}
      LOG_LEVEL: warn
    ports:
      - "127.0.0.1:${PORT}:4242"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: unleash
      POSTGRES_USER: unleash
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
