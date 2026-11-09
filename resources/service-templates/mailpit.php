<?php

return [
    'name' => 'Mailpit',
    'category' => 'Infrastructure',
    'description' => 'Catches outgoing email for testing. SMTP on localhost:1025.',
    'icon' => 'mail',
    'kind' => 'compose',
    'port' => 8025,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mailpit:
    image: axllent/mailpit:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8025"
      - "127.0.0.1:1025:1025"
    volumes:
      - data:/data
    environment:
      MP_DATABASE: /data/mailpit.db
volumes:
  data:
YAML,
    ],
];
