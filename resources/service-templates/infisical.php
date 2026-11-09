<?php

return [
    'name' => 'Infisical',
    'category' => 'Infrastructure',
    'description' => 'Secrets manager for your team and apps, with Postgres and Redis.',
    'icon' => 'lock',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/status',
    'notes' => 'Sign up on first visit to create the admin account. Add a domain: logins need HTTPS outside localhost.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'AUTH_SECRET', 'label' => 'Auth secret', 'default' => '{random:44}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  infisical:
    image: infisical/infisical:latest-postgres
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      NODE_ENV: production
      SITE_URL: ${APP_URL}
      ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      AUTH_SECRET: ${AUTH_SECRET}
      DB_CONNECTION_URI: postgres://infisical:${DB_PASSWORD}@db:5432/infisical
      REDIS_URL: redis://redis:6379
    ports:
      - "127.0.0.1:${PORT}:8080"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: infisical
      POSTGRES_USER: infisical
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  db:
  redis:
YAML,
    ],
];
