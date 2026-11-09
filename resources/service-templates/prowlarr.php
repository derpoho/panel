<?php

return [
    'name' => 'Prowlarr',
    'category' => 'Self-hosted',
    'description' => 'One place to manage indexers for Sonarr, Radarr and Lidarr.',
    'icon' => 'search',
    'kind' => 'compose',
    'port' => 9696,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Open it in the browser to set up sign-in, then add your indexers and connect your other apps by their API keys.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  prowlarr:
    image: lscr.io/linuxserver/prowlarr:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:9696"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
