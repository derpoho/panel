<?php

return [
    'name' => 'imgproxy',
    'category' => 'Infrastructure',
    'description' => 'Fast image resizing and converting server, on the fly from URLs.',
    'icon' => 'image',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'URLs are unsigned by default, so anyone can use it to resize any image. Before you put it on a domain, set IMGPROXY_KEY and IMGPROXY_SALT (hex strings) in the extra environment, or limit IMGPROXY_ALLOWED_SOURCES to your own hosts.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  imgproxy:
    image: ghcr.io/imgproxy/imgproxy:latest
    restart: unless-stopped
    environment:
      IMGPROXY_BIND: ":8080"
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "${DOCKER_BRIDGE}:${PORT}:8080"
YAML,
    ],
];
