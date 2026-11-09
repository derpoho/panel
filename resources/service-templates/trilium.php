<?php

return [
    'name' => 'TriliumNext',
    'category' => 'Self-hosted',
    'description' => 'Hierarchical notes for building a big personal knowledge base.',
    'icon' => 'trilium',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health-check',
    'notes' => 'Open it in the browser to set a password on first visit.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  trilium:
    image: triliumnext/trilium:latest
    restart: unless-stopped
    environment:
      TRILIUM_DATA_DIR: /home/node/trilium-data
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/home/node/trilium-data
volumes:
  data:
YAML,
    ],
];
