<?php

return [
    'name' => 'Redmine',
    'category' => 'Self-hosted',
    'description' => 'Issue tracking and project management, with Postgres.',
    'icon' => 'redmine',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/login',
    'notes' => 'The first login is admin / admin, and it asks you to change the password.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  redmine:
    image: redmine:7
    restart: unless-stopped
    depends_on: [db]
    environment:
      REDMINE_DB_POSTGRES: db
      REDMINE_DB_DATABASE: redmine
      REDMINE_DB_USERNAME: redmine
      REDMINE_DB_PASSWORD: ${DB_PASSWORD}
      REDMINE_SECRET_KEY_BASE: ${SECRET_KEY_BASE}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - files:/usr/src/redmine/files
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: redmine
      POSTGRES_USER: redmine
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  files:
  db:
YAML,
    ],
];
