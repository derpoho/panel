<?php

return [
    'name' => 'Dragonfly',
    'category' => 'Databases',
    'description' => 'Redis- and Memcached-compatible in-memory store, multi-threaded.',
    'icon' => 'dragonfly',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at redis://:PASSWORD@127.0.0.1:PORT. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'DRAGONFLY_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dragonfly:
    image: ghcr.io/dragonflydb/dragonfly:latest
    restart: unless-stopped
    ulimits:
      memlock: -1
    command: ["dragonfly", "--requirepass", "${DRAGONFLY_PASSWORD}", "--dir", "/data"]
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
