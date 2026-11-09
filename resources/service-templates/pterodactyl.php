<?php

return [
    'name' => 'Pterodactyl',
    'category' => 'Game servers',
    'description' => 'Game server panel, the original Pterodactyl. With MariaDB and Redis.',
    'icon' => 'pterodactyl',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/auth/login',
    'notes' => 'Create the admin account from this service’s console with php artisan p:user:make. Game servers run on Wings nodes, which you add from the panel.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Panel URL', 'default' => '{url}', 'hint' => 'Wings and its links use this, so redeploy if the domain changes.'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true, 'hint' => 'Keep it: Pterodactyl encrypts node tokens and other secrets with it.'],
        ['key' => 'HASHIDS_SALT', 'label' => 'Hashids salt', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
        ['key' => 'SERVICE_AUTHOR', 'label' => 'Egg author email', 'default' => 'noreply@example.com', 'hint' => 'Put on eggs you create and export.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  web:
    image: ghcr.io/pterodactyl/panel:latest
    restart: unless-stopped
    depends_on: [db, cache]
    environment:
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      HASHIDS_SALT: ${HASHIDS_SALT}
      HASHIDS_LENGTH: "8"
      APP_TIMEZONE: ${TIMEZONE}
      APP_SERVICE_AUTHOR: ${SERVICE_AUTHOR}
      APP_ENV: production
      APP_ENVIRONMENT_ONLY: "false"
      TRUSTED_PROXIES: "*"
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: panel
      DB_USERNAME: pterodactyl
      DB_PASSWORD: ${DB_PASSWORD}
      CACHE_DRIVER: redis
      SESSION_DRIVER: redis
      QUEUE_DRIVER: redis
      REDIS_HOST: cache
      MAIL_DRIVER: log
      MAIL_FROM: ${SERVICE_AUTHOR}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - var:/app/var
      - nginx:/etc/nginx/http.d
      - logs:/app/storage/logs
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: panel
      MARIADB_USER: pterodactyl
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
  cache:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - cache:/data
volumes:
  var:
  nginx:
  logs:
  db:
  cache:
YAML,
    ],
];
