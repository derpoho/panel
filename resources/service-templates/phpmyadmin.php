<?php

return [
    'name' => 'phpMyAdmin',
    'category' => 'Infrastructure',
    'description' => 'Web admin for MySQL and MariaDB. Type in any server to connect.',
    'icon' => 'phpmyadmin',
    'kind' => 'compose',
    'port' => 8081,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Put it behind a domain only if you need to: it is a login page for your databases.',
    'env' => [
        ['key' => 'UPLOAD_LIMIT', 'label' => 'Import size limit', 'default' => '64M'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  phpmyadmin:
    image: phpmyadmin:5
    restart: unless-stopped
    environment:
      PMA_ARBITRARY: "1"
      UPLOAD_LIMIT: ${UPLOAD_LIMIT}
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
