<?php

return [
    'name' => 'ChartDB',
    'category' => 'Infrastructure',
    'description' => 'Draw database diagrams, or import one from your schema with a single query.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Diagrams are saved in your browser, not on the server. Add an OpenAI key if you want AI DDL export.',
    'env' => [
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI API key', 'default' => '', 'secret' => true, 'hint' => 'Optional'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  chartdb:
    image: ghcr.io/chartdb/chartdb:latest
    restart: unless-stopped
    environment:
      OPENAI_API_KEY: ${OPENAI_API_KEY}
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
