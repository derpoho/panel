<?php

return [
    'name' => 'QuestDB',
    'category' => 'Databases',
    'description' => 'Fast time-series SQL database. Postgres wire protocol on localhost:8812.',
    'icon' => 'questdb',
    'kind' => 'compose',
    'port' => 9000,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The web console is at http://127.0.0.1:PORT, and Postgres clients connect to 127.0.0.1:8812 with the user and password below. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'QDB_USER', 'label' => 'User', 'default' => 'admin'],
        ['key' => 'QDB_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  questdb:
    image: questdb/questdb:latest
    restart: unless-stopped
    environment:
      QDB_PG_USER: ${QDB_USER}
      QDB_PG_PASSWORD: ${QDB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:9000"
      - "${DOCKER_BRIDGE}:${PORT}:9000"
      - "127.0.0.1:8812:8812"
      - "127.0.0.1:9009:9009"
    volumes:
      - data:/var/lib/questdb
volumes:
  data:
YAML,
    ],
];
