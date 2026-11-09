<?php

return [
    'name' => 'TypeDB',
    'category' => 'Databases',
    'description' => 'Strongly typed database for connected data, queried with TypeQL.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 1729,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with TypeDB Studio, the console or a driver at 127.0.0.1:PORT. The first login is admin / password, so change it straight away. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  typedb:
    image: typedb/typedb:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:1729"
      - "${DOCKER_BRIDGE}:${PORT}:1729"
    volumes:
      - data:/var/lib/typedb/data
volumes:
  data:
YAML,
    ],
];
