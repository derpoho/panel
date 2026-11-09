<?php

return [
    'name' => 'Emby',
    'category' => 'Self-hosted',
    'description' => 'Media server for your movies, shows and music, with apps for most devices.',
    'icon' => 'emby',
    'kind' => 'compose',
    'port' => 8096,
    'web' => true,
    'port_editable' => true,
    'health' => '/emby/System/Ping',
    'notes' => 'Open it in the browser to create the admin account and add libraries. Put media in the /media volume.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  emby:
    image: emby/embyserver:latest
    restart: unless-stopped
    environment:
      UID: "1000"
      GID: "1000"
    ports:
      - "127.0.0.1:${PORT}:8096"
    volumes:
      - config:/config
      - media:/media
volumes:
  config:
  media:
YAML,
    ],
];
