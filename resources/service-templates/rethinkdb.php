<?php

return [
    'name' => 'RethinkDB',
    'category' => 'Databases',
    'description' => 'JSON database that pushes live changes to your app. Admin UI on localhost:8080.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 28015,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Drivers connect to 127.0.0.1 on the port you chose as admin with the password below. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'RETHINKDB_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rethinkdb:
    image: rethinkdb:2.4
    restart: unless-stopped
    command: rethinkdb --bind all --directory /data/rethinkdb_data --initial-password ${RETHINKDB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:28015"
      - "${DOCKER_BRIDGE}:${PORT}:28015"
      - "127.0.0.1:8080:8080"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
