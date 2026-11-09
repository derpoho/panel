<?php

return [
    'name' => 'Wiki.js',
    'category' => 'Self-hosted',
    'description' => 'Modern wiki with a Markdown editor, with Postgres.',
    'icon' => 'wikijs',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Create the admin account in the browser on first visit.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wiki:
    image: ghcr.io/requarks/wiki:2
    restart: unless-stopped
    depends_on: [db]
    environment:
      DB_TYPE: postgres
      DB_HOST: db
      DB_PORT: "5432"
      DB_USER: wiki
      DB_PASS: ${DB_PASSWORD}
      DB_NAME: wiki
    ports:
      - "127.0.0.1:${PORT}:3000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: wiki
      POSTGRES_USER: wiki
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
