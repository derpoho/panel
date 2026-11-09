<?php

return [
    'name' => 'PrivateBin',
    'category' => 'Self-hosted',
    'description' => 'Pastebin where the server never sees your text, it is encrypted in the browser.',
    'icon' => 'lock',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'No accounts. Add a domain with HTTPS, the browser needs it for encryption.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  privatebin:
    image: privatebin/nginx-fpm-alpine:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/srv/data
volumes:
  data:
YAML,
    ],
];
