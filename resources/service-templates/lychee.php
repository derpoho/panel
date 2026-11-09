<?php

return [
    'name' => 'Lychee',
    'category' => 'Self-hosted',
    'description' => 'Good looking photo gallery for sharing albums, with MariaDB.',
    'icon' => 'image',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_ROOT_PASSWORD', 'label' => 'Database root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  lychee:
    image: lycheeorg/lychee:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      TRUSTED_PROXIES: "*"
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: 3306
      DB_DATABASE: lychee
      DB_USERNAME: lychee
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - conf:/conf
      - uploads:/uploads
      - sym:/sym
      - logs:/logs
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: lychee
      MARIADB_USER: lychee
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  conf:
  uploads:
  sym:
  logs:
  db:
YAML,
    ],
];
