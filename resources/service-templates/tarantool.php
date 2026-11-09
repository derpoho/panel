<?php

return [
    'name' => 'Tarantool',
    'category' => 'Databases',
    'description' => 'In-memory database and Lua app server, saved to disk with snapshots.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3301,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect a Tarantool client at 127.0.0.1:PORT, or open the admin console from the container with the console command. There is no password by default, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tarantool:
    image: tarantool/tarantool:3
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:3301"
      - "${DOCKER_BRIDGE}:${PORT}:3301"
    volumes:
      - data:/var/lib/tarantool
volumes:
  data:
YAML,
    ],
];
