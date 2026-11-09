<?php

return [
    'name' => 'Owncast',
    'category' => 'Self-hosted',
    'description' => 'Your own live streaming site with chat, works with OBS.',
    'icon' => 'film',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/status',
    'notes' => 'The admin page is at /admin, user admin, password abc123. Change it there, it is also your stream key. OBS streams in over RTMP on TCP port 1935, open to the internet.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  owncast:
    image: owncast/owncast:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "1935:1935"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
