<?php

return [
    'name' => 'SearXNG',
    'category' => 'Self-hosted',
    'description' => 'Private metasearch engine: results from many engines, no tracking.',
    'icon' => 'searxng',
    'kind' => 'compose',
    'port' => 8088,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SEARXNG_SECRET', 'label' => 'Secret key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  searxng:
    image: searxng/searxng:latest
    restart: unless-stopped
    environment:
      SEARXNG_BASE_URL: ${APP_URL}/
      SEARXNG_SECRET: ${SEARXNG_SECRET}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - config:/etc/searxng
volumes:
  config:
YAML,
    ],
];
