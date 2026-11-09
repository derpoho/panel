<?php

return [
    'name' => 'Heimdall',
    'category' => 'Self-hosted',
    'description' => 'A tidy start page with links to all your web apps.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'There is no login by default. Set a password on the admin user in Users if it is public.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  heimdall:
    image: lscr.io/linuxserver/heimdall:latest
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
