<?php

return [
    'name' => 'qBittorrent',
    'category' => 'Self-hosted',
    'description' => 'Torrent client you run from the browser.',
    'icon' => 'qbittorrent',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in as admin. The first password is printed in the service logs, so change it once you are in. Torrents use TCP and UDP port 6881, open to the internet.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  qbittorrent:
    image: lscr.io/linuxserver/qbittorrent:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      WEBUI_PORT: "8080"
      TORRENTING_PORT: "6881"
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "6881:6881/tcp"
      - "6881:6881/udp"
    volumes:
      - config:/config
      - downloads:/downloads
volumes:
  config:
  downloads:
YAML,
    ],
];
