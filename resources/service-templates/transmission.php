<?php

return [
    'name' => 'Transmission',
    'category' => 'Self-hosted',
    'description' => 'Light torrent client with a simple web page.',
    'icon' => 'transmission',
    'kind' => 'compose',
    'port' => 9091,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the user and password below. Torrents use TCP and UDP port 51413, open to the internet.',
    'env' => [
        ['key' => 'WEB_USER', 'label' => 'Web user', 'default' => 'admin'],
        ['key' => 'WEB_PASSWORD', 'label' => 'Web password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  transmission:
    image: lscr.io/linuxserver/transmission:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      USER: ${WEB_USER}
      PASS: ${WEB_PASSWORD}
      PEERPORT: "51413"
    ports:
      - "127.0.0.1:${PORT}:9091"
      - "51413:51413/tcp"
      - "51413:51413/udp"
    volumes:
      - config:/config
      - downloads:/downloads
      - watch:/watch
volumes:
  config:
  downloads:
  watch:
YAML,
    ],
];
