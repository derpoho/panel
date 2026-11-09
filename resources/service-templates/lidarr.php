<?php

return [
    'name' => 'Lidarr',
    'category' => 'Self-hosted',
    'description' => 'Music collection manager that finds and grabs albums by artist.',
    'icon' => 'music',
    'kind' => 'compose',
    'port' => 8686,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Open it in the browser to set up sign-in. Your library lives at /music and downloads at /downloads, both volumes on this service.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  lidarr:
    image: lscr.io/linuxserver/lidarr:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8686"
    volumes:
      - config:/config
      - music:/music
      - downloads:/downloads
volumes:
  config:
  music:
  downloads:
YAML,
    ],
];
