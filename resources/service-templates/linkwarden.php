<?php

return [
    'name' => 'Linkwarden',
    'category' => 'Self-hosted',
    'description' => 'Collaborative bookmark manager that archives the pages you save, with Postgres.',
    'icon' => 'bookmark',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Register in the browser to create your account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'NEXTAUTH_SECRET', 'label' => 'Auth secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  linkwarden:
    image: ghcr.io/linkwarden/linkwarden:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgresql://linkwarden:${DB_PASSWORD}@db:5432/linkwarden
      NEXTAUTH_SECRET: ${NEXTAUTH_SECRET}
      NEXTAUTH_URL: ${APP_URL}/api/v1/auth
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/data/data
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: linkwarden
      POSTGRES_USER: linkwarden
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
