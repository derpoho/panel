<?php

return [
    'name' => 'Dgraph',
    'category' => 'Databases',
    'description' => 'Graph database with a GraphQL API. gRPC on localhost:9080.',
    'icon' => 'dgraph',
    'kind' => 'compose',
    'port' => 8080,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Send GraphQL and DQL to http://127.0.0.1:PORT. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  zero:
    image: dgraph/dgraph:latest
    restart: unless-stopped
    command: dgraph zero --my=zero:5080
    volumes:
      - zero:/dgraph
  alpha:
    image: dgraph/dgraph:latest
    restart: unless-stopped
    depends_on: [zero]
    command: dgraph alpha --my=alpha:7080 --zero=zero:5080 --security whitelist=0.0.0.0/0
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "${DOCKER_BRIDGE}:${PORT}:8080"
      - "127.0.0.1:9080:9080"
    volumes:
      - alpha:/dgraph
volumes:
  zero:
  alpha:
YAML,
    ],
];
