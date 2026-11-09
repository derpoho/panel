<?php

return [
    'name' => 'OrientDB',
    'category' => 'Databases',
    'description' => 'Multi-model database for graphs and documents, with OrientDB Studio.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 2480,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Studio and the HTTP API are at http://127.0.0.1:PORT; log in as root with the password below. Drivers use the binary port below. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'ORIENTDB_ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'BINARY_PORT', 'label' => 'Binary protocol port', 'default' => '2424'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  orientdb:
    image: orientdb:3.2
    restart: unless-stopped
    environment:
      ORIENTDB_ROOT_PASSWORD: ${ORIENTDB_ROOT_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:2480"
      - "${DOCKER_BRIDGE}:${PORT}:2480"
      - "127.0.0.1:${BINARY_PORT}:2424"
      - "${DOCKER_BRIDGE}:${BINARY_PORT}:2424"
    volumes:
      - databases:/orientdb/databases
      - backup:/orientdb/backup
volumes:
  databases:
  backup:
YAML,
    ],
];
