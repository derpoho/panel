<?php

return [
    'name' => 'MySQL',
    'category' => 'Databases',
    'description' => 'MySQL 8.4 LTS, data kept in a volume.',
    'icon' => 'mysql',
    'kind' => 'compose',
    'port' => 3306,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Listens on 127.0.0.1 only. Point your apps at this machine on the port you chose. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'MYSQL_DATABASE', 'label' => 'Database', 'default' => 'app'],
        ['key' => 'MYSQL_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'MYSQL_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MYSQL_ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mysql:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: ${MYSQL_DATABASE}
      MYSQL_USER: ${MYSQL_USER}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
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
