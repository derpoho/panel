<?php

return [
    'name' => 'YugabyteDB',
    'category' => 'Databases',
    'description' => 'Postgres-compatible distributed SQL, on a single node. UI on localhost:15433.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 5433,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect at postgres://yugabyte@127.0.0.1:PORT/yugabyte. There is no password, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  yugabyte:
    image: yugabytedb/yugabyte:latest
    restart: unless-stopped
    command: bin/yugabyted start --background=false --base_dir=/home/yugabyte/yb_data
    ports:
      - "127.0.0.1:${PORT}:5433"
      - "${DOCKER_BRIDGE}:${PORT}:5433"
      - "127.0.0.1:15433:15433"
    volumes:
      - data:/home/yugabyte/yb_data
volumes:
  data:
YAML,
    ],
];
