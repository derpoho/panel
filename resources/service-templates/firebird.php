<?php

return [
    'name' => 'Firebird',
    'category' => 'Databases',
    'description' => 'Firebird 5, the open source SQL database, with a database and user ready to go.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3050,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect at 127.0.0.1/PORT:DATABASE with the user and password below. The SYSDBA password is the admin one. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'FIREBIRD_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'FIREBIRD_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'FIREBIRD_DATABASE', 'label' => 'Database file', 'default' => 'app.fdb'],
        ['key' => 'FIREBIRD_ROOT_PASSWORD', 'label' => 'SYSDBA password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  firebird:
    image: firebirdsql/firebird:5
    restart: unless-stopped
    environment:
      FIREBIRD_ROOT_PASSWORD: ${FIREBIRD_ROOT_PASSWORD}
      FIREBIRD_USER: ${FIREBIRD_USER}
      FIREBIRD_PASSWORD: ${FIREBIRD_PASSWORD}
      FIREBIRD_DATABASE: ${FIREBIRD_DATABASE}
      FIREBIRD_DATABASE_DEFAULT_CHARSET: UTF8
    ports:
      - "127.0.0.1:${PORT}:3050"
      - "${DOCKER_BRIDGE}:${PORT}:3050"
    volumes:
      - data:/var/lib/firebird/data
volumes:
  data:
YAML,
    ],
];
