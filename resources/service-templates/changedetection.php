<?php

return [
    'name' => 'changedetection.io',
    'category' => 'Self-hosted',
    'description' => 'Watches web pages and tells you when they change.',
    'icon' => 'changedetection',
    'kind' => 'compose',
    'port' => 5000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'There is no password at first. Set one under Settings.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  changedetection:
    image: ghcr.io/dgtlmoon/changedetection.io:latest
    restart: unless-stopped
    environment:
      BASE_URL: ${APP_URL}
      USE_X_SETTINGS: 1
    ports:
      - "127.0.0.1:${PORT}:5000"
    volumes:
      - data:/datastore
volumes:
  data:
YAML,
    ],
];
