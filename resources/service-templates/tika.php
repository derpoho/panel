<?php

return [
    'name' => 'Apache Tika',
    'category' => 'Infrastructure',
    'description' => 'Pull text and metadata out of PDFs, Office files and over a thousand other formats.',
    'icon' => 'apache',
    'kind' => 'compose',
    'port' => 9998,
    'web' => true,
    'port_editable' => true,
    'health' => '/tika',
    'notes' => 'Apps on this machine call it at http://{bridge}:PORT. It has no login, so it only listens locally and on the Docker bridge. This is the full image, with OCR.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tika:
    image: apache/tika:latest-full
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9998"
      - "${DOCKER_BRIDGE}:${PORT}:9998"
YAML,
    ],
];
