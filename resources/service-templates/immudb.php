<?php

return [
    'name' => 'immudb',
    'category' => 'Databases',
    'description' => 'Tamper-proof database where every change is kept and can be verified.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3322,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with immuclient or an immudb SDK at 127.0.0.1:PORT as immudb, with the admin password below. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'IMMUDB_ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  immudb:
    image: codenotary/immudb:latest
    restart: unless-stopped
    environment:
      IMMUDB_ADMIN_PASSWORD: ${IMMUDB_ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3322"
      - "${DOCKER_BRIDGE}:${PORT}:3322"
    volumes:
      - data:/var/lib/immudb
volumes:
  data:
YAML,
    ],
];
