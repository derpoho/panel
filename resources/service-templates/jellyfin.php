<?php

return [
    'name' => 'Jellyfin',
    'category' => 'Self-hosted',
    'description' => 'Media server for your films, shows and music.',
    'icon' => 'jellyfin',
    'kind' => 'compose',
    'port' => 8096,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Finish setup in the browser. Copy media into the media volume, or swap it for a folder on the host.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  jellyfin:
    image: jellyfin/jellyfin:latest
    restart: unless-stopped
    environment:
      JELLYFIN_PublishedServerUrl: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8096"
    volumes:
      - config:/config
      - cache:/cache
      - media:/media
volumes:
  config:
  cache:
  media:
YAML,
    ],
];
