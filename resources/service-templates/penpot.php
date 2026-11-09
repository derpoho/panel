<?php

return [
    'name' => 'Penpot',
    'category' => 'Self-hosted',
    'description' => 'Open source design and prototyping tool, like Figma, with Postgres and Valkey.',
    'icon' => 'penpot',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Open it in the browser and register the first account. Email is off, so new accounts skip verification.',
    'env' => [
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  penpot-frontend:
    image: penpotapp/frontend:latest
    restart: unless-stopped
    depends_on: [penpot-backend, penpot-exporter]
    environment:
      PENPOT_FLAGS: disable-email-verification enable-login-with-password enable-registration
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - assets:/opt/data/assets
  penpot-backend:
    image: penpotapp/backend:latest
    restart: unless-stopped
    depends_on: [postgres, valkey]
    environment:
      PENPOT_FLAGS: disable-email-verification enable-login-with-password enable-registration
      PENPOT_PUBLIC_URI: ${PUBLIC_URL}
      PENPOT_SECRET_KEY: ${SECRET_KEY}
      PENPOT_DATABASE_URI: postgresql://postgres/penpot
      PENPOT_DATABASE_USERNAME: penpot
      PENPOT_DATABASE_PASSWORD: ${DB_PASSWORD}
      PENPOT_REDIS_URI: redis://valkey/0
      PENPOT_ASSETS_STORAGE_BACKEND: assets-fs
      PENPOT_STORAGE_ASSETS_FS_DIRECTORY: /opt/data/assets
      PENPOT_TELEMETRY_ENABLED: "false"
    volumes:
      - assets:/opt/data/assets
  penpot-exporter:
    image: penpotapp/exporter:latest
    restart: unless-stopped
    depends_on: [valkey]
    environment:
      PENPOT_SECRET_KEY: ${SECRET_KEY}
      PENPOT_PUBLIC_URI: http://penpot-frontend:8080
      PENPOT_REDIS_URI: redis://valkey/0
  postgres:
    image: postgres:15
    restart: unless-stopped
    environment:
      POSTGRES_DB: penpot
      POSTGRES_USER: penpot
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_INITDB_ARGS: --data-checksums
    volumes:
      - db:/var/lib/postgresql/data
  valkey:
    image: valkey/valkey:8.1
    restart: unless-stopped
volumes:
  assets:
  db:
YAML,
    ],
];
