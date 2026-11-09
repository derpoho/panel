<?php

return [
    'name' => 'Directus',
    'category' => 'Self-hosted',
    'description' => 'Headless CMS and data studio on SQLite.',
    'icon' => 'directus',
    'kind' => 'compose',
    'port' => 8055,
    'web' => true,
    'port_editable' => true,
    'health' => '/server/health',
    'env' => [
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DIRECTUS_SECRET', 'label' => 'Secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  directus:
    image: directus/directus:11
    restart: unless-stopped
    environment:
      SECRET: ${DIRECTUS_SECRET}
      ADMIN_EMAIL: ${ADMIN_EMAIL}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      DB_CLIENT: sqlite3
      DB_FILENAME: /directus/database/data.db
      PUBLIC_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8055"
    volumes:
      - database:/directus/database
      - uploads:/directus/uploads
volumes:
  database:
  uploads:
YAML,
    ],
];
