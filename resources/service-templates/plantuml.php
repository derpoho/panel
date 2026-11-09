<?php

return [
    'name' => 'PlantUML',
    'category' => 'Self-hosted',
    'description' => 'Turns PlantUML text into diagrams, with a web editor and an image API.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => null,
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  plantuml:
    image: plantuml/plantuml-server:jetty
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
