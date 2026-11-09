<?php

return [
    'name' => 'PhotoPrism',
    'category' => 'Self-hosted',
    'description' => 'Browse, organise and share your photo library, with MariaDB.',
    'icon' => 'image',
    'kind' => 'compose',
    'port' => 2342,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/status',
    'notes' => 'Copy photos into the originals volume, then index them under Library.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_ROOT_PASSWORD', 'label' => 'Database root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  photoprism:
    image: photoprism/photoprism:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      PHOTOPRISM_ADMIN_USER: ${ADMIN_USER}
      PHOTOPRISM_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      PHOTOPRISM_SITE_URL: ${APP_URL}
      PHOTOPRISM_DATABASE_DRIVER: mysql
      PHOTOPRISM_DATABASE_SERVER: db:3306
      PHOTOPRISM_DATABASE_NAME: photoprism
      PHOTOPRISM_DATABASE_USER: photoprism
      PHOTOPRISM_DATABASE_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:2342"
    volumes:
      - originals:/photoprism/originals
      - storage:/photoprism/storage
  db:
    image: mariadb:11
    restart: unless-stopped
    command: --innodb-buffer-pool-size=512M --transaction-isolation=READ-COMMITTED --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --max-connections=512 --innodb-rollback-on-timeout=OFF --innodb-lock-wait-timeout=120
    environment:
      MARIADB_AUTO_UPGRADE: "1"
      MARIADB_DATABASE: photoprism
      MARIADB_USER: photoprism
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  originals:
  storage:
  db:
YAML,
    ],
];
