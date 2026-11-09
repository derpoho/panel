<?php

return [
    'name' => 'GlitchTip',
    'category' => 'Infrastructure',
    'description' => 'Error tracking that works with the Sentry SDKs, plus uptime checks.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/_health/',
    'notes' => 'Sign up on the first visit: the first account becomes the admin. Point your Sentry SDKs at the DSN each project shows. Without SMTP, emails only go to the logs. It runs with Postgres and Valkey.',
    'env' => [
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'EMAIL_URL', 'label' => 'SMTP URL', 'default' => 'consolemail://', 'hint' => 'Like smtp://user:password@smtp.example.com:587'],
        ['key' => 'FROM_EMAIL', 'label' => 'From address', 'default' => 'glitchtip@example.com'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  web:
    image: glitchtip/glitchtip:6
    restart: unless-stopped
    depends_on: [db, valkey]
    environment:
      DATABASE_URL: postgres://glitchtip:${DB_PASSWORD}@db:5432/glitchtip
      VALKEY_URL: redis://valkey:6379
      SECRET_KEY: ${SECRET_KEY}
      EMAIL_URL: ${EMAIL_URL}
      GLITCHTIP_DOMAIN: ${APP_URL}
      DEFAULT_FROM_EMAIL: ${FROM_EMAIL}
      SERVER_ROLE: all_in_one
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - uploads:/code/uploads
  db:
    image: postgres:17
    restart: unless-stopped
    command: -c max_locks_per_transaction=512
    environment:
      POSTGRES_DB: glitchtip
      POSTGRES_USER: glitchtip
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  valkey:
    image: valkey/valkey:8
    restart: unless-stopped
volumes:
  uploads:
  db:
YAML,
    ],
];
