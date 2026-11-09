<?php

return [
    'name' => 'IT Tools',
    'category' => 'Infrastructure',
    'description' => 'Handy tools for developers in one page, like encoders, converters and generators.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Everything runs in the browser and nothing is stored, so there is no login.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  it-tools:
    image: corentinth/it-tools:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
