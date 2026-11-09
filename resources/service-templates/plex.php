<?php

return [
    'name' => 'Plex',
    'category' => 'Self-hosted',
    'description' => 'Stream your movies, shows and music to any device.',
    'icon' => 'plex',
    'kind' => 'compose',
    'port' => 32400,
    'web' => true,
    'port_editable' => true,
    'health' => '/identity',
    'notes' => 'Get a claim code from plex.tv/claim right before deploying, it only lasts 4 minutes. Then open /web to set up your libraries. Put media in the /media volume.',
    'env' => [
        ['key' => 'PLEX_CLAIM', 'label' => 'Claim code', 'default' => '', 'secret' => true, 'hint' => 'From plex.tv/claim, starts with claim-'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  plex:
    image: lscr.io/linuxserver/plex:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      VERSION: docker
      PLEX_CLAIM: ${PLEX_CLAIM}
    ports:
      - "127.0.0.1:${PORT}:32400"
    volumes:
      - config:/config
      - media:/media
volumes:
  config:
  media:
YAML,
    ],
];
