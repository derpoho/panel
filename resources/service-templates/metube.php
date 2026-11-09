<?php

return [
    'name' => 'MeTube',
    'category' => 'Self-hosted',
    'description' => 'Paste a link and download video or audio from YouTube and other sites, with yt-dlp.',
    'icon' => 'film',
    'kind' => 'compose',
    'port' => 8081,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'No sign-in, so keep it private or put it behind a login. Downloads go to the /downloads volume.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  metube:
    image: ghcr.io/alexta69/metube:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8081"
    volumes:
      - downloads:/downloads
volumes:
  downloads:
YAML,
    ],
];
