<?php

return [
    'name' => 'Uptime Kuma',
    'category' => 'Self-hosted',
    'description' => 'Status pages and uptime monitoring.',
    'icon' => 'uptime-kuma',
    'kind' => 'compose',
    'port' => 3001,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/entry-page',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  uptime-kuma:
    image: louislam/uptime-kuma:1
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:3001"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
