<?php

return [
    'name' => 'httpbin',
    'category' => 'Infrastructure',
    'description' => 'HTTP request and response service for testing clients and webhooks.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/status/200',
    'notes' => 'This runs go-httpbin, a maintained rewrite of the original httpbin with the same endpoints.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  httpbin:
    image: mccutchen/go-httpbin:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
