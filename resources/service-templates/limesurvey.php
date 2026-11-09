<?php

return [
    'name' => 'LimeSurvey',
    'category' => 'Self-hosted',
    'description' => 'Build surveys and collect the answers on your own server, with MariaDB.',
    'icon' => 'limesurvey',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in at /index.php/admin with admin and the admin password you set here.',
    'env' => [
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => '', 'required' => true],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  limesurvey:
    image: martialblog/limesurvey:7-apache
    restart: unless-stopped
    depends_on: [db]
    environment:
      DB_TYPE: mysql
      DB_HOST: db
      DB_NAME: limesurvey
      DB_USERNAME: limesurvey
      DB_PASSWORD: ${DB_PASSWORD}
      ADMIN_USER: admin
      ADMIN_EMAIL: ${ADMIN_EMAIL}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - upload:/var/www/html/upload
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: limesurvey
      MARIADB_USER: limesurvey
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  upload:
  db:
YAML,
    ],
];
