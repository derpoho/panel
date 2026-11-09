<?php

return [
    'name' => 'Joomla',
    'category' => 'Self-hosted',
    'description' => 'The open source CMS for websites and portals, with MariaDB.',
    'icon' => 'joomla',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the installer. The database is already filled in: host db, database joomla, user joomla.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  joomla:
    image: joomla:6-apache
    restart: unless-stopped
    depends_on: [db]
    environment:
      JOOMLA_DB_HOST: db
      JOOMLA_DB_NAME: joomla
      JOOMLA_DB_USER: joomla
      JOOMLA_DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - site:/var/www/html
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: joomla
      MARIADB_USER: joomla
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  site:
  db:
YAML,
    ],
];
