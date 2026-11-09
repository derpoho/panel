<?php

return [
    'name' => 'drawDB',
    'category' => 'Infrastructure',
    'description' => 'Database diagram editor in the browser that exports SQL.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Diagrams are saved in your browser, not on the server.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  drawdb:
    image: ghcr.io/drawdb-io/drawdb:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
