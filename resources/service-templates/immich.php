<?php

return [
    'name' => 'Immich',
    'category' => 'Self-hosted',
    'description' => 'Photo and video backup from your phone, with face and object search.',
    'icon' => 'immich',
    'kind' => 'compose',
    'port' => 2283,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/server/ping',
    'notes' => 'Open it in the browser to create the admin account. Machine learning needs a few GB of memory.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  immich-server:
    image: ghcr.io/immich-app/immich-server:release
    restart: unless-stopped
    depends_on: [redis, database]
    environment:
      TZ: ${TIMEZONE}
      DB_HOSTNAME: database
      DB_USERNAME: postgres
      DB_PASSWORD: ${DB_PASSWORD}
      DB_DATABASE_NAME: immich
      REDIS_HOSTNAME: redis
    ports:
      - "127.0.0.1:${PORT}:2283"
    volumes:
      - upload:/data
      - /etc/localtime:/etc/localtime:ro
  immich-machine-learning:
    image: ghcr.io/immich-app/immich-machine-learning:release
    restart: unless-stopped
    volumes:
      - model-cache:/cache
  redis:
    image: docker.io/valkey/valkey:8-bookworm
    restart: unless-stopped
  database:
    image: ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0
    restart: unless-stopped
    shm_size: 128mb
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: immich
      POSTGRES_INITDB_ARGS: --data-checksums
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  upload:
  model-cache:
  db:
YAML,
    ],
];
