<?php

return [
    'name' => 'Apache Kvrocks',
    'category' => 'Databases',
    'description' => 'Redis-compatible database that keeps data on disk with RocksDB.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 6666,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Works with any Redis client, using the password below. Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'KVROCKS_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kvrocks:
    image: apache/kvrocks:latest
    restart: unless-stopped
    command: ["--requirepass", "${KVROCKS_PASSWORD}"]
    ports:
      - "127.0.0.1:${PORT}:6666"
      - "${DOCKER_BRIDGE}:${PORT}:6666"
    volumes:
      - data:/var/lib/kvrocks
volumes:
  data:
YAML,
    ],
];
