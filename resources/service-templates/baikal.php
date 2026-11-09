<?php

return [
    'name' => 'Baikal',
    'category' => 'Self-hosted',
    'description' => 'CalDAV and CardDAV server with a web admin, for syncing calendars and contacts.',
    'icon' => 'calendar',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run setup and set the admin password. SQLite works fine for a few users.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  baikal:
    image: ckulka/baikal:nginx
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/var/www/baikal/config
      - data:/var/www/baikal/Specific
volumes:
  config:
  data:
YAML,
    ],
];
