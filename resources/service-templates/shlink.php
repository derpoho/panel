<?php

return [
    'name' => 'Shlink',
    'category' => 'Self-hosted',
    'description' => 'A URL shortener with an API and visit stats.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/rest/health',
    'notes' => 'Shlink is the API and redirect server. Manage it from app.shlink.io (or the shlink-web-client image) with your public URL and the API key from here.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Short link domain', 'default' => '', 'required' => true, 'hint' => 'Just the host name, like s.example.com'],
        ['key' => 'API_KEY', 'label' => 'API key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  shlink:
    image: shlinkio/shlink:stable
    restart: unless-stopped
    environment:
      DEFAULT_DOMAIN: ${DOMAIN}
      IS_HTTPS_ENABLED: "true"
      INITIAL_API_KEY: ${API_KEY}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/etc/shlink/data
volumes:
  data:
YAML,
    ],
];
