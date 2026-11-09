<?php

return [
    'name' => 'DbGate',
    'category' => 'Infrastructure',
    'description' => 'Database manager in the browser for SQL and NoSQL databases.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the user and password you set here. For databases on this machine, connect to {bridge} and their port.',
    'env' => [
        ['key' => 'ADMIN_USER', 'label' => 'User', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dbgate:
    image: dbgate/dbgate:latest
    restart: unless-stopped
    environment:
      LOGIN: ${ADMIN_USER}
      PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/root/.dbgate
volumes:
  data:
YAML,
    ],
];
