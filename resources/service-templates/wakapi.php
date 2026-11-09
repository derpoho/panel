<?php

return [
    'name' => 'Wakapi',
    'category' => 'Self-hosted',
    'description' => 'Coding time tracker that works with the WakaTime editor plugins.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Sign up in the browser, then point your WakaTime plugin at {url}/api with the API key from Settings.',
    'env' => [
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'PASSWORD_SALT', 'label' => 'Password salt', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wakapi:
    image: ghcr.io/muety/wakapi:latest
    restart: unless-stopped
    environment:
      WAKAPI_PUBLIC_URL: ${PUBLIC_URL}
      WAKAPI_PASSWORD_SALT: ${PASSWORD_SALT}
      WAKAPI_DB_TYPE: sqlite3
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
