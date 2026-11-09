<?php

return [
    'name' => 'Shiori',
    'category' => 'Self-hosted',
    'description' => 'Simple bookmark manager that can keep offline copies of pages.',
    'icon' => 'bookmark',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first login is shiori / gopher. Make your own account, and the default one goes away.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  shiori:
    image: ghcr.io/go-shiori/shiori:latest
    restart: unless-stopped
    environment:
      SHIORI_DIR: /shiori
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/shiori
volumes:
  data:
YAML,
    ],
];
