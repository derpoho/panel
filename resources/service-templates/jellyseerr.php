<?php

return [
    'name' => 'Jellyseerr',
    'category' => 'Self-hosted',
    'description' => 'Let friends request movies and shows for your Jellyfin, Emby or Plex.',
    'icon' => 'film',
    'kind' => 'compose',
    'port' => 5055,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/status',
    'notes' => 'Open it in the browser and sign in with your media server account, then connect Sonarr and Radarr.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  jellyseerr:
    image: fallenbagel/jellyseerr:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:5055"
    volumes:
      - config:/app/config
volumes:
  config:
YAML,
    ],
];
