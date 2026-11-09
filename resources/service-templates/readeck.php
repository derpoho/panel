<?php

return [
    'name' => 'Readeck',
    'category' => 'Self-hosted',
    'description' => 'Keep the web pages you want to read later, highlight them and make ebooks.',
    'icon' => 'bookmark',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the first account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  readeck:
    image: codeberg.org/readeck/readeck:latest
    restart: unless-stopped
    environment:
      READECK_LOG_LEVEL: info
      READECK_SERVER_HOST: 0.0.0.0
      READECK_SERVER_PORT: 8000
      READECK_USE_X_FORWARDED: "true"
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - data:/readeck
volumes:
  data:
YAML,
    ],
];
