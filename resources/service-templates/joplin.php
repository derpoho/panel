<?php

return [
    'name' => 'Joplin Server',
    'category' => 'Self-hosted',
    'description' => 'Sync server for the Joplin notes apps, with Postgres.',
    'icon' => 'joplin',
    'kind' => 'compose',
    'port' => 22300,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/ping',
    'notes' => 'The first login is admin@localhost / admin. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  joplin:
    image: joplin/server:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      APP_PORT: 22300
      APP_BASE_URL: ${APP_URL}
      DB_CLIENT: pg
      POSTGRES_HOST: db
      POSTGRES_PORT: 5432
      POSTGRES_DATABASE: joplin
      POSTGRES_USER: joplin
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:22300"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: joplin
      POSTGRES_USER: joplin
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
