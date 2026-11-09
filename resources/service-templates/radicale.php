<?php

return [
    'name' => 'Radicale',
    'category' => 'Self-hosted',
    'description' => 'Small CalDAV and CardDAV server for your calendars and contacts.',
    'icon' => 'calendar',
    'kind' => 'compose',
    'port' => 5232,
    'web' => true,
    'port_editable' => true,
    'health' => '/.web/',
    'notes' => 'Out of the box any username and password is accepted. Add an htpasswd users file and auth settings to the config volume before you share the address.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  radicale:
    image: tomsquest/docker-radicale:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:5232"
    volumes:
      - data:/data
      - config:/config
volumes:
  data:
  config:
YAML,
    ],
];
