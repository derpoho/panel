<?php

return [
    'name' => 'Audiobookshelf',
    'category' => 'Self-hosted',
    'description' => 'Audiobook and podcast server with apps for your phone.',
    'icon' => 'audiobookshelf',
    'kind' => 'compose',
    'port' => 13378,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck',
    'notes' => 'Create the root user in the browser. Books go in the audiobooks volume, podcasts in the podcasts one.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  audiobookshelf:
    image: ghcr.io/advplyr/audiobookshelf:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - audiobooks:/audiobooks
      - podcasts:/podcasts
      - config:/config
      - metadata:/metadata
volumes:
  audiobooks:
  podcasts:
  config:
  metadata:
YAML,
    ],
];
