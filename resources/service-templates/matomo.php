<?php

return [
    'name' => 'Matomo',
    'category' => 'Self-hosted',
    'description' => 'Website analytics you own, an alternative to Google Analytics, with MariaDB.',
    'icon' => 'matomo',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the setup. The database details are already filled in, so you just create the superuser and add your first site.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  matomo:
    image: matomo:5-apache
    restart: unless-stopped
    depends_on: [db]
    environment:
      MATOMO_DATABASE_HOST: db
      MATOMO_DATABASE_ADAPTER: mysql
      MATOMO_DATABASE_TABLES_PREFIX: matomo_
      MATOMO_DATABASE_USERNAME: matomo
      MATOMO_DATABASE_PASSWORD: ${DB_PASSWORD}
      MATOMO_DATABASE_DBNAME: matomo
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - matomo:/var/www/html
  db:
    image: mariadb:11
    restart: unless-stopped
    command: --max-allowed-packet=64MB
    environment:
      MARIADB_DATABASE: matomo
      MARIADB_USER: matomo
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  matomo:
  db:
YAML,
    ],
];
