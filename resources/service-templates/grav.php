<?php

return [
    'name' => 'Grav',
    'category' => 'Self-hosted',
    'description' => 'A fast flat-file CMS, no database needed.',
    'icon' => 'grav',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open /admin to create the admin account.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  grav:
    image: lscr.io/linuxserver/grav:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
