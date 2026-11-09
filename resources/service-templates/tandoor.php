<?php

return [
    'name' => 'Tandoor Recipes',
    'category' => 'Self-hosted',
    'description' => 'Save recipes, plan meals and build shopping lists, with Postgres.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account.',
    'env' => [
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tandoor:
    image: vabene1111/recipes:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      SECRET_KEY: ${SECRET_KEY}
      TZ: ${TIMEZONE}
      DB_ENGINE: django.db.backends.postgresql
      POSTGRES_HOST: db
      POSTGRES_PORT: "5432"
      POSTGRES_DB: tandoor
      POSTGRES_USER: tandoor
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - staticfiles:/opt/recipes/staticfiles
      - mediafiles:/opt/recipes/mediafiles
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: tandoor
      POSTGRES_USER: tandoor
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  staticfiles:
  mediafiles:
  db:
YAML,
    ],
];
