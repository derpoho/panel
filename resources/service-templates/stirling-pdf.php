<?php

return [
    'name' => 'Stirling-PDF',
    'category' => 'Self-hosted',
    'description' => 'Merge, split, convert, sign and OCR PDFs in the browser.',
    'icon' => 'pdf',
    'kind' => 'compose',
    'port' => 8084,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Files are processed on this machine and not kept. It has no login, so think twice before adding a domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  stirling-pdf:
    image: stirlingtools/stirling-pdf:latest
    restart: unless-stopped
    environment:
      DOCKER_ENABLE_SECURITY: "false"
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - tessdata:/usr/share/tessdata
      - config:/configs
volumes:
  tessdata:
  config:
YAML,
    ],
];
