<?php

return [
    'name' => 'authentik',
    'category' => 'Infrastructure',
    'description' => 'Identity provider for single sign-on: OAuth, SAML, LDAP and proxy auth.',
    'icon' => 'authentik',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/-/health/live/',
    'notes' => 'Go to /if/flow/initial-setup/ to set the password for akadmin, the first admin. It runs a server and a background worker, with Postgres.',
    'env' => [
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:60}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-authentik: &authentik
  image: ghcr.io/goauthentik/server:2026.8
  restart: unless-stopped
  depends_on: [db]
  environment:
    AUTHENTIK_SECRET_KEY: ${SECRET_KEY}
    AUTHENTIK_POSTGRESQL__HOST: db
    AUTHENTIK_POSTGRESQL__NAME: authentik
    AUTHENTIK_POSTGRESQL__USER: authentik
    AUTHENTIK_POSTGRESQL__PASSWORD: ${DB_PASSWORD}
  volumes:
    - media:/media
    - templates:/templates

services:
  server:
    <<: *authentik
    command: server
    ports:
      - "127.0.0.1:${PORT}:9000"
  worker:
    <<: *authentik
    command: worker
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: authentik
      POSTGRES_USER: authentik
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  media:
  templates:
  db:
YAML,
    ],
];
