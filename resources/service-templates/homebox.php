<?php

return [
    'name' => 'Homebox',
    'category' => 'Self-hosted',
    'description' => 'A home inventory for your stuff, where it is and what it cost.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 7745,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/status',
    'notes' => 'Open it in the browser to register the first account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  homebox:
    image: ghcr.io/sysadminsmedia/homebox:latest
    restart: unless-stopped
    environment:
      HBOX_LOG_LEVEL: info
    ports:
      - "127.0.0.1:${PORT}:7745"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
