<?php

return [
    'name' => 'Redis',
    'category' => 'Databases',
    'description' => 'Redis 7 with a password and append-only persistence.',
    'icon' => 'redis',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'REDIS_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--requirepass", "${REDIS_PASSWORD}", "--appendonly", "yes"]
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
