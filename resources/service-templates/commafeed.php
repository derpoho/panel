<?php

return [
    'name' => 'CommaFeed',
    'category' => 'Self-hosted',
    'description' => 'Google Reader style feed reader.',
    'icon' => 'rss',
    'kind' => 'compose',
    'port' => 8082,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first login is admin / admin. Change it straight away.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  commafeed:
    image: athou/commafeed:latest-h2
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8082"
    volumes:
      - data:/commafeed/data
volumes:
  data:
YAML,
    ],
];
