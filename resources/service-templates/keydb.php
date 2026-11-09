<?php

return [
    'name' => 'KeyDB',
    'category' => 'Databases',
    'description' => 'Multithreaded Redis fork, with a password and append-only persistence.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Works with any Redis client. Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'KEYDB_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  keydb:
    image: eqalpha/keydb:latest
    restart: unless-stopped
    command: keydb-server --bind 0.0.0.0 --protected-mode no --requirepass ${KEYDB_PASSWORD} --appendonly yes --dir /data --server-threads 2
    ports:
      - "127.0.0.1:${PORT}:6379"
      - "${DOCKER_BRIDGE}:${PORT}:6379"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
