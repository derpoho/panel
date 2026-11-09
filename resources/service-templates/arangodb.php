<?php

return [
    'name' => 'ArangoDB',
    'category' => 'Databases',
    'description' => 'Multi-model database for documents, graphs and key-value, with a web UI.',
    'icon' => 'arangodb',
    'kind' => 'compose',
    'port' => 8529,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Log in as root with the password below. Drivers and the web UI use http://127.0.0.1:PORT. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'ARANGO_ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  arangodb:
    image: arangodb:latest
    restart: unless-stopped
    environment:
      ARANGO_ROOT_PASSWORD: ${ARANGO_ROOT_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8529"
      - "${DOCKER_BRIDGE}:${PORT}:8529"
    volumes:
      - data:/var/lib/arangodb3
      - apps:/var/lib/arangodb3-apps
volumes:
  data:
  apps:
YAML,
    ],
];
