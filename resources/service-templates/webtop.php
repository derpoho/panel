<?php

return [
    'name' => 'Webtop',
    'category' => 'Self-hosted',
    'description' => 'A full Linux desktop that runs in your browser tab.',
    'icon' => 'linuxserver',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the user and password below. Some features like clipboard need HTTPS, so open it on its domain. Your home folder lives in the config volume.',
    'env' => [
        ['key' => 'CUSTOM_USER', 'label' => 'Username', 'default' => 'admin'],
        ['key' => 'PASSWORD', 'label' => 'Password', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  webtop:
    image: lscr.io/linuxserver/webtop:latest
    restart: unless-stopped
    shm_size: 1gb
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TZ}
      CUSTOM_USER: ${CUSTOM_USER}
      PASSWORD: ${PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
