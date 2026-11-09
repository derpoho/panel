<?php

return [
    'name' => 'pgweb',
    'category' => 'Infrastructure',
    'description' => 'Small web client for browsing and querying Postgres.',
    'icon' => 'postgresql',
    'kind' => 'compose',
    'port' => 8081,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The browser asks for the user and password you set here, then you connect to a database. For Postgres on this machine, use {bridge} as the host.',
    'env' => [
        ['key' => 'AUTH_USER', 'label' => 'User', 'default' => 'admin'],
        ['key' => 'AUTH_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pgweb:
    image: sosedoff/pgweb:latest
    restart: unless-stopped
    command: ["/usr/bin/pgweb", "--bind=0.0.0.0", "--listen=8081", "--sessions", "--auth-user=${AUTH_USER}", "--auth-pass=${AUTH_PASSWORD}"]
    ports:
      - "127.0.0.1:${PORT}:8081"
YAML,
    ],
];
