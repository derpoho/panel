<?php

return [
    'name' => 'Snipe-IT',
    'category' => 'Self-hosted',
    'description' => 'Track IT assets, licences and who has which laptop, with MariaDB.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the setup and create the admin account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  snipe-it:
    image: snipe/snipe-it:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      APP_ENV: production
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      APP_TIMEZONE: ${TIMEZONE}
      APP_TRUSTED_PROXIES: "*"
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: snipeit
      DB_USERNAME: snipeit
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - storage:/var/lib/snipeit
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: snipeit
      MARIADB_USER: snipeit
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  storage:
  db:
YAML,
    ],
];
