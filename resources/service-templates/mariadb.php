<?php

return [
    'name' => 'MariaDB',
    'category' => 'Databases',
    'description' => 'MariaDB 11, a drop-in MySQL replacement.',
    'icon' => 'mariadb',
    'kind' => 'compose',
    'port' => 3306,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'MARIADB_DATABASE', 'label' => 'Database', 'default' => 'app'],
        ['key' => 'MARIADB_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'MARIADB_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MARIADB_ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mariadb:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${MARIADB_ROOT_PASSWORD}
      MARIADB_DATABASE: ${MARIADB_DATABASE}
      MARIADB_USER: ${MARIADB_USER}
      MARIADB_PASSWORD: ${MARIADB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3306"
      - "${DOCKER_BRIDGE}:${PORT}:3306"
    volumes:
      - data:/var/lib/mysql
volumes:
  data:
YAML,
    ],
];
