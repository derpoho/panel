<?php

return [
    'name' => 'NZBGet',
    'category' => 'Self-hosted',
    'description' => 'Fast, light Usenet downloader with a web page.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 6789,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the user and password below, then add your Usenet provider under Settings, News-Servers.',
    'env' => [
        ['key' => 'WEB_USER', 'label' => 'Web user', 'default' => 'admin'],
        ['key' => 'WEB_PASSWORD', 'label' => 'Web password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nzbget:
    image: lscr.io/linuxserver/nzbget:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      NZBGET_USER: ${WEB_USER}
      NZBGET_PASS: ${WEB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:6789"
    volumes:
      - config:/config
      - downloads:/downloads
volumes:
  config:
  downloads:
YAML,
    ],
];
