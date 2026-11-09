<?php

return [
    'name' => 'Sonarr',
    'category' => 'Self-hosted',
    'description' => 'Watches for new TV episodes and sends them to your download client.',
    'icon' => 'sonarr',
    'kind' => 'compose',
    'port' => 8989,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Open it in the browser to set up sign-in. Your library lives at /tv and downloads at /downloads, both volumes on this service.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sonarr:
    image: lscr.io/linuxserver/sonarr:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8989"
    volumes:
      - config:/config
      - tv:/tv
      - downloads:/downloads
volumes:
  config:
  tv:
  downloads:
YAML,
    ],
];
