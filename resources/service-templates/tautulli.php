<?php

return [
    'name' => 'Tautulli',
    'category' => 'Self-hosted',
    'description' => 'Stats, history and alerts for your Plex server.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 8181,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'Open it in the browser and sign in with your Plex account to connect your server.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tautulli:
    image: lscr.io/linuxserver/tautulli:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8181"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
