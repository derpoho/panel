<?php

return [
    'name' => 'XWiki',
    'category' => 'Self-hosted',
    'description' => 'Structured wiki platform with apps and extensions, with Postgres.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first start takes a few minutes, then a setup wizard creates the admin account.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  xwiki:
    image: xwiki:stable-postgres-tomcat
    restart: unless-stopped
    depends_on: [db]
    environment:
      DB_HOST: db
      DB_DATABASE: xwiki
      DB_USER: xwiki
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/usr/local/xwiki
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: xwiki
      POSTGRES_USER: xwiki
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_INITDB_ARGS: --encoding=UTF8
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
