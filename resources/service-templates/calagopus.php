<?php

return [
    'name' => 'Calagopus',
    'category' => 'Game servers',
    'description' => 'Game server panel, a Rust rebuild of Pterodactyl. With Postgres and Valkey.',
    'icon' => 'calagopus',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/settings',
    'notes' => 'Finish the setup wizard in the browser to create the admin account. Game servers run on Wings nodes, which you add from the panel.',
    'env' => [
        ['key' => 'APP_ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:48}', 'secret' => true, 'hint' => 'Keep it: Calagopus encrypts stored secrets with it.'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  web:
    image: ghcr.io/calagopus/panel:latest
    restart: unless-stopped
    depends_on: [db, cache]
    environment:
      TZ: ${TIMEZONE}
      REDIS_URL: redis://cache
      DATABASE_URL: postgresql://panel:${DB_PASSWORD}@db/panel
      DATABASE_MIGRATE: "true"
      PORT: "8000"
      APP_DEBUG: "false"
      APP_LOG_DIRECTORY: /var/log/calagopus
      APP_PRIMARY: "true"
      APP_ENABLE_WINGS_PROXY: "true"
      APP_ENCRYPTION_KEY: ${APP_ENCRYPTION_KEY}
      APP_USE_DECRYPTION_CACHE: "true"
      APP_USE_INTERNAL_CACHE: "true"
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - data:/var/lib/calagopus
      - logs:/var/log/calagopus
  db:
    image: ghcr.io/calagopus/pgautoupgrade:18-alpine
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
      POSTGRES_USER: panel
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: panel
      PGDATA: /data
    volumes:
      - db:/data
  cache:
    image: ghcr.io/calagopus/valkey:latest
    restart: unless-stopped
    command: ["--protected-mode", "no", "--save", "60", "1"]
    volumes:
      - cache:/data
volumes:
  data:
  logs:
  db:
  cache:
YAML,
    ],
];
