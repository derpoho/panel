<?php

return [
    'name' => 'Paperless-ngx',
    'category' => 'Self-hosted',
    'description' => 'Scan, OCR and search your paper documents, with Postgres and Redis.',
    'icon' => 'paperless',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/accounts/login/',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'OCR_LANGUAGE', 'label' => 'OCR language', 'default' => 'eng', 'hint' => 'Tesseract codes, like eng, deu or fra.'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  paperless:
    image: ghcr.io/paperless-ngx/paperless-ngx:latest
    restart: unless-stopped
    depends_on: [db, broker]
    environment:
      PAPERLESS_URL: ${APP_URL}
      PAPERLESS_ALLOWED_HOSTS: "*"
      PAPERLESS_SECRET_KEY: ${SECRET_KEY}
      PAPERLESS_ADMIN_USER: ${ADMIN_USER}
      PAPERLESS_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      PAPERLESS_REDIS: redis://broker:6379
      PAPERLESS_DBHOST: db
      PAPERLESS_DBNAME: paperless
      PAPERLESS_DBUSER: paperless
      PAPERLESS_DBPASS: ${DB_PASSWORD}
      PAPERLESS_OCR_LANGUAGE: ${OCR_LANGUAGE}
      PAPERLESS_TIME_ZONE: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - data:/usr/src/paperless/data
      - media:/usr/src/paperless/media
      - consume:/usr/src/paperless/consume
  broker:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: paperless
      POSTGRES_USER: paperless
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  media:
  consume:
  redis:
  db:
YAML,
    ],
];
