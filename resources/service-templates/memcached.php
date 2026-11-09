<?php

return [
    'name' => 'Memcached',
    'category' => 'Databases',
    'description' => 'Simple in-memory key-value cache.',
    'icon' => 'memcached',
    'kind' => 'compose',
    'port' => 11211,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at 127.0.0.1:PORT. It has no password, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'MEMORY_MB', 'label' => 'Memory (MB)', 'default' => '256'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  memcached:
    image: memcached:1-alpine
    restart: unless-stopped
    command: ["memcached", "-m", "${MEMORY_MB}"]
    ports:
      - "127.0.0.1:${PORT}:11211"
      - "${DOCKER_BRIDGE}:${PORT}:11211"
YAML,
    ],
];
