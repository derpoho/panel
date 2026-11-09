<?php

return [
    'name' => 'DokuWiki',
    'category' => 'Self-hosted',
    'description' => 'Simple wiki that keeps pages in plain text files, no database needed.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Finish setup at /install.php, where you create the admin account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dokuwiki:
    image: lscr.io/linuxserver/dokuwiki:latest
    restart: unless-stopped
    environment:
      PUID: 1000
      PGID: 1000
      TZ: Etc/UTC
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
