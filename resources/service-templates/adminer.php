<?php

return [
    'name' => 'Adminer',
    'category' => 'Infrastructure',
    'description' => 'Database manager in one page, for Postgres, MySQL and more.',
    'icon' => 'adminer',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Put it behind a domain only if you need to: it is a login page for your databases.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  adminer:
    image: adminer:4
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
