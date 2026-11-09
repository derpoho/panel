<?php

return [
    'name' => 'Mautic',
    'category' => 'Self-hosted',
    'description' => 'Marketing automation for emails, forms and campaigns, with MariaDB.',
    'icon' => 'mautic',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the installer and create the admin account. The database details are already filled in. Cron jobs and the queue worker run in their own containers.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-mautic: &mautic
  image: mautic/mautic:5-apache
  restart: unless-stopped
  depends_on: [db]
  volumes:
    - config:/var/www/html/config
    - logs:/var/www/html/var/logs
    - media-files:/var/www/html/docroot/media/files
    - media-images:/var/www/html/docroot/media/images
services:
  mautic_web:
    <<: *mautic
    environment:
      DOCKER_MAUTIC_ROLE: mautic_web
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: ${DB_PASSWORD}
      MAUTIC_MESSENGER_DSN_EMAIL: doctrine://default
      MAUTIC_MESSENGER_DSN_HIT: doctrine://default
    ports:
      - "127.0.0.1:${PORT}:80"
  mautic_cron:
    <<: *mautic
    depends_on: [mautic_web]
    environment:
      DOCKER_MAUTIC_ROLE: mautic_cron
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: ${DB_PASSWORD}
  mautic_worker:
    <<: *mautic
    depends_on: [mautic_web]
    environment:
      DOCKER_MAUTIC_ROLE: mautic_worker
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: ${DB_PASSWORD}
      MAUTIC_MESSENGER_DSN_EMAIL: doctrine://default
      MAUTIC_MESSENGER_DSN_HIT: doctrine://default
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: mautic
      MARIADB_USER: mautic
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  config:
  logs:
  media-files:
  media-images:
  db:
YAML,
    ],
];
