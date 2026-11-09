<?php

return [
    'name' => 'Monica',
    'category' => 'Self-hosted',
    'description' => 'A personal CRM to remember the people in your life, with MariaDB.',
    'icon' => 'monica',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to register. The first account is yours.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  monica:
    image: monica:4-apache
    restart: unless-stopped
    depends_on: [db]
    environment:
      APP_ENV: production
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      APP_TRUSTED_PROXIES: "*"
      DB_HOST: db
      DB_DATABASE: monica
      DB_USERNAME: monica
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - storage:/var/www/html/storage
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: monica
      MARIADB_USER: monica
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
