<?php

return [
    'name' => 'Pinchflat',
    'category' => 'Self-hosted',
    'description' => 'Follows YouTube channels and playlists and downloads new videos for you.',
    'icon' => 'film',
    'kind' => 'compose',
    'port' => 8945,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck',
    'notes' => 'No sign-in by default, so keep it private or put it behind a login. Videos go to the /downloads volume.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pinchflat:
    image: ghcr.io/kieraneglin/pinchflat:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8945"
    volumes:
      - config:/config
      - downloads:/downloads
volumes:
  config:
  downloads:
YAML,
    ],
];
