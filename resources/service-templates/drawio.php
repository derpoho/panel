<?php

return [
    'name' => 'draw.io',
    'category' => 'Self-hosted',
    'description' => 'Draw diagrams, flowcharts and network maps in the browser.',
    'icon' => 'diagramsdotnet',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'There are no accounts. Diagrams save to your own device or storage, not to the server.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  drawio:
    image: jgraph/drawio:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
