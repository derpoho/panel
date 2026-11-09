<?php

return [
    'name' => 'BookStack',
    'category' => 'Self-hosted',
    'description' => 'Documentation organised into shelves, books and pages, with MariaDB.',
    'icon' => 'bookstack',
    'kind' => 'compose',
    'port' => 6875,
    'web' => true,
    'port_editable' => true,
    'health' => '/login',
    'notes' => 'The first login is admin@admin.com / password. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}', 'hint' => 'BookStack builds its links from this, so redeploy if the domain changes.'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  bookstack:
    image: linuxserver/bookstack:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: bookstack
      DB_USERNAME: bookstack
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: bookstack
      MARIADB_USER: bookstack
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  config:
  db:
YAML,
    ],
];
