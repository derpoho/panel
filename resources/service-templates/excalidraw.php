<?php

return [
    'name' => 'Excalidraw',
    'category' => 'Self-hosted',
    'description' => 'Hand-drawn style whiteboard for sketches and diagrams.',
    'icon' => 'excalidraw',
    'kind' => 'compose',
    'port' => 8085,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Drawings are saved in each browser, not on the server.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  excalidraw:
    image: excalidraw/excalidraw:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
