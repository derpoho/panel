<?php

return [
    'name' => 'Redis Stack',
    'category' => 'Databases',
    'description' => 'Redis with search, JSON, time series and probabilistic types built in.',
    'icon' => 'redis',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Has a password and append-only persistence. Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'REDIS_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  redis:
    image: redis/redis-stack-server:latest
    restart: unless-stopped
    environment:
      REDIS_ARGS: --requirepass ${REDIS_PASSWORD} --appendonly yes
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
