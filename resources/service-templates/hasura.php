<?php

return [
    'name' => 'Hasura',
    'category' => 'Infrastructure',
    'description' => 'Instant GraphQL API over Postgres, with its console.',
    'icon' => 'hasura',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Open /console and sign in with the admin secret. Requests to /v1/graphql need the x-hasura-admin-secret header or a JWT.',
    'env' => [
        ['key' => 'ADMIN_SECRET', 'label' => 'Admin secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  hasura:
    image: hasura/graphql-engine:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      HASURA_GRAPHQL_DATABASE_URL: postgres://hasura:${DB_PASSWORD}@db:5432/hasura
      HASURA_GRAPHQL_METADATA_DATABASE_URL: postgres://hasura:${DB_PASSWORD}@db:5432/hasura
      HASURA_GRAPHQL_ADMIN_SECRET: ${ADMIN_SECRET}
      HASURA_GRAPHQL_ENABLE_CONSOLE: "true"
      HASURA_GRAPHQL_DEV_MODE: "false"
    ports:
      - "127.0.0.1:${PORT}:8080"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: hasura
      POSTGRES_USER: hasura
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
