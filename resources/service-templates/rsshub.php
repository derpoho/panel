<?php

return [
    'name' => 'RSSHub',
    'category' => 'Self-hosted',
    'description' => 'Feed generator for thousands of sites, with Redis for caching.',
    'icon' => 'rss',
    'kind' => 'compose',
    'port' => 1200,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Feeds need ?key= and the access key on the end when one is set.',
    'env' => [
        ['key' => 'ACCESS_KEY', 'label' => 'Access key', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rsshub:
    image: diygod/rsshub:latest
    restart: unless-stopped
    depends_on: [redis]
    environment:
      NODE_ENV: production
      CACHE_TYPE: redis
      REDIS_URL: redis://redis:6379/
      ACCESS_KEY: ${ACCESS_KEY}
    ports:
      - "127.0.0.1:${PORT}:1200"
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  redis:
YAML,
    ],
];
