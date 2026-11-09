<?php

return [
    'name' => 'PeerTube',
    'category' => 'Self-hosted',
    'description' => 'Your own video site that can follow other PeerTube instances, with Postgres and Redis.',
    'icon' => 'peertube',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/ping',
    'notes' => 'Needs a domain with HTTPS, set below before the first start because PeerTube cannot change it later. Sign in as root with the password below.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'default' => '', 'required' => true, 'hint' => 'Just the host name, like videos.example.com'],
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => '', 'required' => true],
        ['key' => 'ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'PEERTUBE_SECRET', 'label' => 'Secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  peertube:
    image: chocobozzz/peertube:production-bookworm
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      PEERTUBE_WEBSERVER_HOSTNAME: ${DOMAIN}
      PEERTUBE_WEBSERVER_PORT: "443"
      PEERTUBE_WEBSERVER_HTTPS: "true"
      PEERTUBE_TRUST_PROXY: '["loopback", "linklocal", "uniquelocal"]'
      PEERTUBE_SECRET: ${PEERTUBE_SECRET}
      PEERTUBE_ADMIN_EMAIL: ${ADMIN_EMAIL}
      PT_INITIAL_ROOT_PASSWORD: ${ROOT_PASSWORD}
      PEERTUBE_DB_HOSTNAME: db
      PEERTUBE_DB_USERNAME: peertube
      PEERTUBE_DB_PASSWORD: ${DB_PASSWORD}
      PEERTUBE_DB_NAME: peertube
      PEERTUBE_DB_SSL: "false"
      PEERTUBE_REDIS_HOSTNAME: redis
    ports:
      - "127.0.0.1:${PORT}:9000"
    volumes:
      - data:/data
      - config:/config
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: peertube
      POSTGRES_USER: peertube
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  data:
  config:
  db:
  redis:
YAML,
    ],
];
