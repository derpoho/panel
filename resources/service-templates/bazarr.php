<?php

return [
    'name' => 'Bazarr',
    'category' => 'Self-hosted',
    'description' => 'Downloads subtitles for your Sonarr and Radarr libraries.',
    'icon' => 'film',
    'kind' => 'compose',
    'port' => 6767,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser, then connect Sonarr and Radarr by their addresses and API keys. Subtitles land next to the media in /tv and /movies.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  bazarr:
    image: lscr.io/linuxserver/bazarr:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:6767"
    volumes:
      - config:/config
      - tv:/tv
      - movies:/movies
volumes:
  config:
  tv:
  movies:
YAML,
    ],
];
