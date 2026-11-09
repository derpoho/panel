<?php

return [
    'name' => 'SurrealDB',
    'category' => 'Databases',
    'description' => 'Multi-model database with SQL-like queries, stored with RocksDB.',
    'icon' => 'surrealdb',
    'kind' => 'compose',
    'port' => 8000,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at ws://127.0.0.1:PORT/rpc or http://127.0.0.1:PORT. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'SURREAL_USER', 'label' => 'Root user', 'default' => 'root'],
        ['key' => 'SURREAL_PASS', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  surrealdb:
    image: surrealdb/surrealdb:v2
    restart: unless-stopped
    user: root
    command: ["start", "--user", "${SURREAL_USER}", "--pass", "${SURREAL_PASS}", "rocksdb:/data/database.db"]
    ports:
      - "127.0.0.1:${PORT}:8000"
      - "${DOCKER_BRIDGE}:${PORT}:8000"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
