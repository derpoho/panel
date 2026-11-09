<?php

return [
    'name' => 'Ampache',
    'category' => 'Self-hosted',
    'description' => 'Stream your music collection from the browser or any Subsonic app.',
    'icon' => 'music',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the installer. The database admin password is printed in the service logs on first start. Put music in the /media volume.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ampache:
    image: ampache/ampache:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/var/www/config
      - log:/var/log/ampache
      - mysql:/var/lib/mysql
      - media:/media
volumes:
  config:
  log:
  mysql:
  media:
YAML,
    ],
];
