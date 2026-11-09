<?php

return [
    'name' => 'MongoDB',
    'category' => 'Databases',
    'description' => 'MongoDB 8 document database with a root user.',
    'icon' => 'mongodb',
    'kind' => 'compose',
    'port' => 27017,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'MONGO_USER', 'label' => 'Root user', 'default' => 'admin'],
        ['key' => 'MONGO_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mongodb:
    image: mongo:8
    restart: unless-stopped
    environment:
      MONGO_INITDB_ROOT_USERNAME: ${MONGO_USER}
      MONGO_INITDB_ROOT_PASSWORD: ${MONGO_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:27017"
      - "${DOCKER_BRIDGE}:${PORT}:27017"
    volumes:
      - data:/data/db
volumes:
  data:
YAML,
    ],
];
