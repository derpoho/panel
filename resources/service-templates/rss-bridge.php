<?php

return [
    'name' => 'RSS-Bridge',
    'category' => 'Self-hosted',
    'description' => 'Makes RSS feeds for sites that do not have one.',
    'icon' => 'rss',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rss-bridge:
    image: rssbridge/rss-bridge:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
