<?php

return [
    'name' => 'CockroachDB',
    'category' => 'Databases',
    'description' => 'Postgres-compatible distributed SQL, as a secure single node. DB Console on localhost:8080.',
    'icon' => 'cockroachlabs',
    'kind' => 'compose',
    'port' => 26257,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect at postgresql://USER:PASSWORD@127.0.0.1:PORT/DATABASE?sslmode=require. It makes its own certificates on first start and only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'COCKROACH_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'COCKROACH_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'COCKROACH_DATABASE', 'label' => 'Database', 'default' => 'app'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  cockroach:
    image: cockroachdb/cockroach:latest
    restart: unless-stopped
    command: start-single-node --http-addr=0.0.0.0:8080
    environment:
      COCKROACH_USER: ${COCKROACH_USER}
      COCKROACH_PASSWORD: ${COCKROACH_PASSWORD}
      COCKROACH_DATABASE: ${COCKROACH_DATABASE}
    ports:
      - "127.0.0.1:${PORT}:26257"
      - "${DOCKER_BRIDGE}:${PORT}:26257"
      - "127.0.0.1:8080:8080"
    volumes:
      - data:/cockroach/cockroach-data
      - certs:/cockroach/certs
volumes:
  data:
  certs:
YAML,
    ],
];
