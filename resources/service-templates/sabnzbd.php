<?php

return [
    'name' => 'SABnzbd',
    'category' => 'Self-hosted',
    'description' => 'Usenet downloader that handles the whole job from NZB to unpacked files.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser and follow the setup wizard to add your Usenet provider. If you add a domain and it says access denied, add the domain to host_whitelist under Config, Special.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sabnzbd:
    image: lscr.io/linuxserver/sabnzbd:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - config:/config
      - downloads:/downloads
      - incomplete:/incomplete-downloads
volumes:
  config:
  downloads:
  incomplete:
YAML,
    ],
];
