<?php

return [
    'name' => 'Logto',
    'category' => 'Infrastructure',
    'description' => 'Auth for your apps: sign-in, social logins, MFA and organisations over OIDC.',
    'icon' => 'shield',
    'kind' => 'compose',
    'port' => 3001,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/status',
    'notes' => 'The main port is the auth endpoint your apps use. The admin console runs on the admin port and only listens locally: reach it over an SSH tunnel, or deploy with an admin URL and point a second domain at that port. Create the admin account on its first visit. It runs with Postgres.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_PORT', 'label' => 'Admin console port', 'default' => '3002'],
        ['key' => 'ADMIN_URL', 'label' => 'Admin console URL', 'default' => '', 'hint' => 'Optional, like https://logto-admin.example.com'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  logto:
    image: svhd/logto:latest
    restart: unless-stopped
    depends_on: [db]
    entrypoint: ["sh", "-c", "npm run cli db seed -- --swe && npm start"]
    environment:
      TRUST_PROXY_HEADER: 1
      DB_URL: postgres://logto:${DB_PASSWORD}@db:5432/logto
      ENDPOINT: ${APP_URL}
      ADMIN_ENDPOINT: ${ADMIN_URL}
    ports:
      - "127.0.0.1:${PORT}:3001"
      - "127.0.0.1:${ADMIN_PORT}:3002"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: logto
      POSTGRES_USER: logto
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
