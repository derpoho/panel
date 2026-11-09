<?php

return [
    'name' => 'Radarr',
    'category' => 'Self-hosted',
    'description' => 'Finds and grabs movies for you and keeps your library tidy.',
    'icon' => 'radarr',
    'kind' => 'compose',
    'port' => 7878,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Open it in the browser to set up sign-in. Your library lives at /movies and downloads at /downloads, both volumes on this service.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  radarr:
    image: lscr.io/linuxserver/radarr:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:7878"
    volumes:
      - config:/config
      - movies:/movies
      - downloads:/downloads
volumes:
  config:
  movies:
  downloads:
YAML,
    ],
];
