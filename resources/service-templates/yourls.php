<?php

return [
    'name' => 'YOURLS',
    'category' => 'Self-hosted',
    'description' => 'Your own URL shortener with click stats, with MariaDB.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open /admin to finish the install, then sign in with the admin user and password from here.',
    'env' => [
        ['key' => 'SITE_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  yourls:
    image: yourls:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      YOURLS_SITE: ${SITE_URL}
      YOURLS_USER: ${ADMIN_USER}
      YOURLS_PASS: ${ADMIN_PASSWORD}
      YOURLS_DB_HOST: db
      YOURLS_DB_NAME: yourls
      YOURLS_DB_USER: yourls
      YOURLS_DB_PASS: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: yourls
      MARIADB_USER: yourls
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  db:
YAML,
    ],
];
