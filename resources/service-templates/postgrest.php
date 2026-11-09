<?php

return [
    'name' => 'PostgREST',
    'category' => 'Infrastructure',
    'description' => 'Turns a Postgres database into a REST API, Postgres included.',
    'icon' => 'postgresql',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Tables in the public schema show up as endpoints. Anonymous requests run as the web_anon role, which can only read; send a JWT signed with the JWT secret for more. Create tables with docker compose exec db psql -U app app.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:40}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  postgrest:
    image: postgrest/postgrest:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      PGRST_DB_URI: postgres://app:${DB_PASSWORD}@db:5432/app
      PGRST_DB_SCHEMAS: public
      PGRST_DB_ANON_ROLE: web_anon
      PGRST_JWT_SECRET: ${JWT_SECRET}
      PGRST_SERVER_PORT: 3000
    ports:
      - "127.0.0.1:${PORT}:3000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: app
      POSTGRES_USER: app
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
      - ./init.sql:/docker-entrypoint-initdb.d/init.sql:ro
volumes:
  db:
YAML,
        'init.sql' => <<<'SQL'
create role web_anon nologin;
grant usage on schema public to web_anon;
alter default privileges in schema public grant select on tables to web_anon;
SQL,
    ],
];
