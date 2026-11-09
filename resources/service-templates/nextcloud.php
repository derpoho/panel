<?php

return [
    'name' => 'Nextcloud',
    'category' => 'Self-hosted',
    'description' => 'Files, calendar and contacts in your own cloud, with Postgres and Redis.',
    'icon' => 'nextcloud',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/status.php',
    'notes' => 'The first boot installs Nextcloud, which takes a minute or two.',
    'env' => [
        ['key' => 'TRUSTED_DOMAIN', 'label' => 'Domain', 'default' => 'localhost', 'hint' => 'The domain you will open it on, like cloud.example.com.'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nextcloud:
    image: nextcloud:30-apache
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      POSTGRES_HOST: db
      POSTGRES_DB: nextcloud
      POSTGRES_USER: nextcloud
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      REDIS_HOST: redis
      NEXTCLOUD_ADMIN_USER: ${ADMIN_USER}
      NEXTCLOUD_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      NEXTCLOUD_TRUSTED_DOMAINS: localhost 127.0.0.1 ${TRUSTED_DOMAIN}
      TRUSTED_PROXIES: 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - html:/var/www/html
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: nextcloud
      POSTGRES_USER: nextcloud
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
volumes:
  html:
  db:
YAML,
    ],
];
