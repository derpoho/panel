<?php

return [
    'name' => 'Palworld',
    'category' => 'Game servers',
    'description' => 'Dedicated Palworld server with admin password and backups.',
    'icon' => 'palworld',
    'kind' => 'compose',
    'port' => 8211,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'UDP, open to the internet. Needs at least 8 GB of RAM.',
    'env' => [
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS Palworld'],
        ['key' => 'PLAYERS', 'label' => 'Max players', 'default' => '16'],
        ['key' => 'SERVER_PASSWORD', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  palworld:
    image: thijsvanloef/palworld-server-docker:latest
    restart: unless-stopped
    stop_grace_period: 30s
    environment:
      PORT: "8211"
      PLAYERS: ${PLAYERS}
      SERVER_NAME: ${SERVER_NAME}
      SERVER_PASSWORD: ${SERVER_PASSWORD}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      MULTITHREADING: "true"
      COMMUNITY: "false"
    ports:
      - "${PORT}:8211/udp"
    volumes:
      - data:/palworld
volumes:
  data:
YAML,
    ],
];
