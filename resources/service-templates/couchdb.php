<?php

return [
    'name' => 'CouchDB',
    'category' => 'Databases',
    'description' => 'Document database with an HTTP API and the Fauxton admin UI.',
    'icon' => 'couchdb',
    'kind' => 'compose',
    'port' => 5984,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at http://USER:PASSWORD@127.0.0.1:PORT. Fauxton is at /_utils. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'COUCHDB_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'COUCHDB_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  couchdb:
    image: couchdb:3
    restart: unless-stopped
    environment:
      COUCHDB_USER: ${COUCHDB_USER}
      COUCHDB_PASSWORD: ${COUCHDB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:5984"
      - "${DOCKER_BRIDGE}:${PORT}:5984"
    volumes:
      - data:/opt/couchdb/data
volumes:
  data:
YAML,
    ],
];
