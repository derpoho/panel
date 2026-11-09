<?php

return [
    'name' => 'Shynet',
    'category' => 'Self-hosted',
    'description' => 'Lightweight, privacy-friendly web analytics with no cookies, with Postgres.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Make the admin account by running ./manage.py registeradmin you@example.com in the shynet container. It prints the password.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  shynet:
    image: milesmcc/shynet:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DJANGO_SECRET_KEY: ${SECRET_KEY}
      ALLOWED_HOSTS: "*"
      CSRF_TRUSTED_ORIGINS: ${APP_URL}
      DB_HOST: db
      DB_PORT: "5432"
      DB_NAME: shynet
      DB_USER: shynet
      DB_PASSWORD: ${DB_PASSWORD}
      TIME_ZONE: ${TIMEZONE}
      PORT: "8080"
      ACCOUNT_SIGNUPS_ENABLED: "False"
      SCRIPT_USE_HTTPS: "True"
      PERFORM_CHECKS_AND_SETUP: "True"
    ports:
      - "127.0.0.1:${PORT}:8080"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: shynet
      POSTGRES_USER: shynet
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
