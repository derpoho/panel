<?php

return [
    'name' => 'MediaWiki',
    'category' => 'Self-hosted',
    'description' => 'The wiki software behind Wikipedia, with MariaDB.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Run the installer in the browser. The database host is db, the database and user are both wiki, and the password is the database password below. Then put the LocalSettings.php it gives you in /var/www/html in the container.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_ROOT_PASSWORD', 'label' => 'Database root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mediawiki:
    image: mediawiki:latest
    restart: unless-stopped
    depends_on: [db]
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - html:/var/www/html
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: wiki
      MARIADB_USER: wiki
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  html:
  db:
YAML,
    ],
];
