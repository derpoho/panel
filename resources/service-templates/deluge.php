<?php

return [
    'name' => 'Deluge',
    'category' => 'Self-hosted',
    'description' => 'Torrent client with plugins and a web page.',
    'icon' => 'deluge',
    'kind' => 'compose',
    'port' => 8112,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The web password is deluge, change it once you are in. Torrents use TCP and UDP port 6881, open to the internet.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  deluge:
    image: lscr.io/linuxserver/deluge:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8112"
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
