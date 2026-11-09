<?php

return [
    'name' => 'Calibre-Web',
    'category' => 'Self-hosted',
    'description' => 'Browse, read and download the books in your Calibre library.',
    'icon' => 'calibreweb',
    'kind' => 'compose',
    'port' => 8083,
    'web' => true,
    'port_editable' => true,
    'health' => '/login',
    'notes' => 'Sign in as admin with password admin123 and change it. Copy your Calibre library (with metadata.db) into the /books volume, then point the setup page at /books.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  calibre-web:
    image: lscr.io/linuxserver/calibre-web:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8083"
    volumes:
      - config:/config
      - books:/books
volumes:
  config:
  books:
YAML,
    ],
];
