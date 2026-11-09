<?php

return [
    'name' => 'Piwigo',
    'category' => 'Self-hosted',
    'description' => 'Photo gallery with albums, tags and user permissions, with MariaDB.',
    'icon' => 'piwigo',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Finish the install in the browser. The database host is db, the database and user are both piwigo, and the password is the database password below.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_ROOT_PASSWORD', 'label' => 'Database root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  piwigo:
    image: lscr.io/linuxserver/piwigo:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      PUID: 1000
      PGID: 1000
      TZ: Etc/UTC
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
      - gallery:/gallery
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: piwigo
      MARIADB_USER: piwigo
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  config:
  gallery:
  db:
YAML,
    ],
];
